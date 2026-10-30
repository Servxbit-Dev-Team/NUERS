#!/usr/bin/env python3
"""Opt-in encrypted archive replication. No destination is provisioned by this tool."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
import threading

ARCHIVES = Path('/var/lib/nuers-operator/archives')
PROFILE = Path('/var/lib/nuers-operator/offsite.json')
RESULT = Path('/var/lib/nuers-operator/offsite-last.json')

def digest(stream):
    h = hashlib.sha256()
    for block in iter(lambda: stream.read(1024 * 1024), b''):
        h.update(block)
    return h.hexdigest()

def verify_source(path, expected):
    if path.is_symlink() or not path.is_file() or not re.fullmatch(r'[0-9a-f]{64}', expected):
        raise ValueError('Require a regular encrypted archive and recorded SHA-256.')
    with path.open('rb') as f:
        if f.read(9) != b'NUERSBK1\n':
            raise ValueError('Only NUERS encrypted envelopes may be replicated.')
        f.seek(0)
        actual = digest(f)
    if actual != expected:
        raise ValueError('Source checksum differs from the successful backup record.')
    return actual

def private_file(path):
    st = path.stat()
    if path.is_symlink() or not path.is_file() or st.st_uid != 0 or st.st_mode & 0o077:
        raise ValueError('Operator configuration must be root-owned mode 0600.')

def destination(profile, archive):
    remote = profile.get('destination', '')
    if not profile.get('enabled') or not re.fullmatch(r'[A-Za-z0-9_-]+:[A-Za-z0-9][A-Za-z0-9/_-]*', remote) or '..' in remote:
        raise ValueError('A reviewed remote storage destination must be provisioned and enabled first.')
    return remote.rstrip('/') + '/' + archive

def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('archive_id')
    p.add_argument('sha256')
    p.add_argument('--execute', action='store_true', help='Copy one encrypted archive and verify its remote bytes')
    args = p.parse_args()
    if os.geteuid() != 0:
        raise ValueError('Run only through the root-controlled operator workflow.')
    if not re.fullmatch(r'[0-9]{8}-[0-9]{6}-[a-f0-9]{16}', args.archive_id):
        raise ValueError('Invalid archive identifier.')
    private_file(PROFILE)
    cfg = json.loads(PROFILE.read_text())
    config = Path(cfg['rclone_config'])
    private_file(config)
    binary = Path(cfg['rclone_binary'])
    if not binary.is_absolute() or not binary.is_file() or binary.stat().st_uid != 0 or binary.stat().st_mode & 0o022:
        raise ValueError('Use a trusted root-owned rclone installation.')
    path = ARCHIVES / (args.archive_id + '.nuers')
    sha = verify_source(path, args.sha256)
    remote = destination(cfg, path.name)
    result = {'archive_id': args.archive_id, 'sha256': sha, 'executed': False, 'remote_bytes_verified': False,
              'independent_host_restore_verified': False, 'key_transferred': False, 'object_lock_verified': False}
    if args.execute:
        start = time.monotonic()
        # Explicit config and minimal environment: no inherited RCLONE_* endpoint/TLS overrides.
        env = {'PATH': '/usr/bin:/bin', 'HOME': '/root', 'LANG': 'C.UTF-8'}
        command = [str(binary), '--config', str(config), '--retries', '2', '--low-level-retries', '2', '--timeout', '2m']
        subprocess.run(command + ['copyto', '--immutable', str(path), remote], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True, timeout=1800)
        with subprocess.Popen(command + ['cat', remote], env=env, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL) as proc:
            # Streaming verification avoids retaining a second plaintext or secret-bearing file.
            deadline = threading.Timer(1800, proc.kill)
            deadline.daemon = True
            deadline.start()
            try:
                remote_sha = digest(proc.stdout)
                code = proc.wait(timeout=30)
            finally:
                deadline.cancel()
        if code != 0 or remote_sha != sha:
            raise ValueError('Remote encrypted-object readback did not match the source checksum.')
        result.update(executed=True, remote_bytes_verified=True, duration_seconds=round(time.monotonic() - start, 3), observed_at=time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()))
        os.umask(0o077)
        tmp = RESULT.with_suffix('.tmp')
        with tmp.open('x') as f:
            json.dump(result, f, indent=2)
        tmp.replace(RESULT)
    print(json.dumps(result, indent=2))

if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('Offsite replication not completed. Check provisioned destination, private configuration and checksum evidence. No credentials or provider error bodies disclosed.', file=sys.stderr)
        sys.exit(1)
