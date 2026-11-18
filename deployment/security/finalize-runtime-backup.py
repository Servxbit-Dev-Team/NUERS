#!/usr/bin/env python3
"""Complete the approved backup separation; never execute app-owned code as root."""
import datetime
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys

ROOT = Path('/var/lib/nuers-operator')
BASE = Path('/var/www/vhosts/nuers.net/httpdocs')
OLD = Path('/var/www/vhosts/nuers.net/private-recovery/operator')
PHP = '/opt/plesk/php/8.4/bin/php'
OWNER = 'nuers.net_6r9gw36fpkr'

def run(args, app=False):
    if app:
        args = ['/usr/sbin/runuser', '-u', OWNER, '--'] + args
    return subprocess.run(args, cwd=BASE, capture_output=True, check=True, timeout=180).stdout.decode()

def trusted(path):
    s = path.lstat()
    if stat.S_ISLNK(s.st_mode) or s.st_uid != 0 or s.st_mode & 0o022:
        raise RuntimeError('Operator trust boundary failed')

def main():
    if os.geteuid() != 0:
        raise RuntimeError('Root operator required')
    os.umask(0o077)
    for path in [Path('/var'), Path('/var/lib'), ROOT, ROOT/'database.json', ROOT/'archive.key',
                 Path('/usr/local/lib/nuers-operator/operator-backup.php')]:
        trusted(path)
    verification = json.loads(run([PHP, '/usr/local/lib/nuers-operator/operator-backup.php', '--verify-latest']))
    if not verification['archive_authenticated'] or not verification['file_hashes_verified']:
        raise RuntimeError('Archive verification failed')
    if any(ROOT.glob('.verify-*')) or any(ROOT.glob('.stage-*')):
        raise RuntimeError('Temporary plaintext cleanup incomplete')
    if OLD.exists():
        trusted(OLD)
        if (ROOT/'legacy-cutover-evidence').exists():
            raise RuntimeError('Evidence destination exists; review manually')
        shutil.move(str(OLD), str(ROOT/'legacy-cutover-evidence'))
    env_path = BASE/'.env'
    if env_path.is_symlink():
        raise RuntimeError('Unexpected environment symlink')
    previous = env_path.read_bytes()
    env = previous.decode()
    metadata = env_path.stat()
    rollback = ROOT/('backup-config-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S')+'.env')
    rollback.write_bytes(previous)
    values = {
        'NUERS_BACKUP_DIRECTORY': str(ROOT/'archives'),
        'NUERS_BACKUP_KEY_FILE': str(ROOT/'archive.key'),
        'NUERS_BACKUP_APP_SCHEDULER_ENABLED': 'false',
    }
    def write_env(payload):
        temp = ROOT/'environment.next'
        temp.write_bytes(payload)
        os.chown(temp, metadata.st_uid, metadata.st_gid)
        os.chmod(temp, 0o600)
        os.replace(temp, env_path)
    for name, value in values.items():
        line = name+'="'+value+'"'
        pattern = r'^'+name+r'=[^\r\n]*'
        env = re.sub(pattern, lambda _: line, env, flags=re.M) if re.search(pattern, env, flags=re.M) else env.rstrip()+'\n'+line+'\n'
    try:
        write_env(env.encode())
        run([PHP, 'artisan', 'config:cache'], app=True)
        grants = json.loads(run([PHP, 'artisan', 'operations:database-privileges'], app=True))
        health = json.loads(run([PHP, 'artisan', 'operations:check'], app=True))
        integrity = json.loads(run([PHP, 'artisan', 'operations:integrity'], app=True))
        if not grants['least_privilege_passed'] or not health['acceptance_ready'] or not integrity['valid']:
            raise RuntimeError('Post-configuration check failed')
        run([PHP, 'artisan', 'queue:restart'], app=True)
        run(['systemctl', 'reload', 'plesk-php84-fpm'])
        run(['systemctl', 'enable', '--now', 'nuers-operator-backup.timer'])
    except Exception:
        write_env(previous)
        run([PHP, 'artisan', 'config:cache'], app=True)
        raise
    readable = subprocess.run(['/usr/sbin/runuser', '-u', OWNER, '--', 'test', '-r', str(ROOT/'database.json')]).returncode == 0
    if readable:
        raise RuntimeError('Operator profile readable by application')
    result = {
        'observed_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'verification': verification, 'temporary_plaintext_removed': True,
        'backup': json.loads((ROOT/'last-backup.json').read_text()),
        'operator_profile_readable_by_app': readable, 'runtime': grants,
        'acceptance_ready': health['acceptance_ready'], 'integrity': integrity,
        'backup_timer': run(['systemctl', 'is-active', 'nuers-operator-backup.timer']).strip(),
        'tls_timer': run(['systemctl', 'is-active', 'nuers-renew-tls.timer']).strip(),
        'rollback_evidence': str(ROOT/'legacy-cutover-evidence'),
    }
    (ROOT/'hardening-retest.json').write_text(json.dumps(result, indent=2))
    print(json.dumps(result, indent=2))

if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('Backup finalization failed; inspect private state. No sensitive error disclosed.', file=sys.stderr)
        sys.exit(1)
