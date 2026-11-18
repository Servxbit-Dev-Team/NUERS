#!/usr/bin/env python3
import datetime, pathlib, subprocess, sys, ipaddress
expiry = datetime.datetime(2026, 11, 23, 9, tzinfo=datetime.timezone.utc)
expired = datetime.datetime.now(datetime.timezone.utc) >= expiry
if '--check' in sys.argv:
    sys.exit(1 if expired else 0)
if not expired and '--revoke-now' not in sys.argv:
    sys.exit(0)
for args in [
    ['systemctl', 'stop', 'nuers-vapt-tester-ssh.service'],
    ['usermod', '--expiredate', '1970-01-02', 'vapt_auditor'],
    ['pkill', '-KILL', '-u', 'vapt_auditor'],
]:
    subprocess.run(args, capture_output=True)
host = str(ipaddress.ip_address(pathlib.Path('/srv/nuers-vapt/tester-20261116/infra-access/db-host').read_text().strip()))
command = '''set -eu
umask 077
f=$(mktemp /tmp/vapt-client.XXXXXX)
trap 'rm -f "$f"' EXIT
printf '[client]\\nuser=root\\npassword=%s\\n' "$MARIADB_ROOT_PASSWORD" > "$f"
mariadb --defaults-extra-file="$f" --batch --skip-column-names
'''
sql = f"ALTER USER IF EXISTS 'vapt_reader'@'{host}' ACCOUNT LOCK;\n"
result = subprocess.run(['docker','exec','-i','nuers-multi-db','sh','-c',command], input=sql, text=True, capture_output=True)
print('SSH access revoked; database account ' + ('locked.' if result.returncode == 0 else 'lock pending: database unavailable.'))
sys.exit(result.returncode)
