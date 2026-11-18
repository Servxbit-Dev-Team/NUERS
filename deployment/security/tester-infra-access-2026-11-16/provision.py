#!/usr/bin/env python3
"""Run on approved isolated staging Node A only. Never prints credentials."""
import datetime, ipaddress, json, os, pathlib, pwd, secrets, subprocess, sys

ROOT = pathlib.Path('/srv/nuers-vapt/tester-20261116/infra-access')
HERE = pathlib.Path(__file__).resolve().parent
USER = 'vapt_auditor'
EXPIRY = datetime.datetime(2026, 11, 23, 9, tzinfo=datetime.timezone.utc)
DB = 'nuers_vapt_multi'
created_user = False


def run(args, **kw):
    return subprocess.run(args, check=True, capture_output=True, text=True, **kw).stdout.strip()


def sql(statement):
    # Root password stays inside the existing DB container and never enters argv.
    command = '''set -eu
umask 077
f=$(mktemp /tmp/vapt-client.XXXXXX)
trap 'rm -f "$f"' EXIT
printf '[client]\\nuser=root\\npassword=%s\\n' "$MARIADB_ROOT_PASSWORD" > "$f"
mariadb --defaults-extra-file="$f" --batch --skip-column-names
'''
    return run(['docker', 'exec', '-i', 'nuers-multi-db', 'sh', '-c', command], input=statement)


def write(path, data, mode=0o600):
    path = pathlib.Path(path)
    path.write_text(data)
    path.chmod(mode)


def main():
    global created_user
    assert os.geteuid() == 0
    assert datetime.datetime.now(datetime.timezone.utc) < EXPIRY
    assert '10.85.0.70' in run(['hostname', '-I']).split()
    assert pathlib.Path('/srv/nuers-vapt/tester-20261116/runtime.env').is_file()
    assert pathlib.Path('/srv/nuers-vapt/tester-20261116/handoff.crt').is_file()
    assert not ROOT.exists(), 'Existing access must be reviewed, never silently replaced'
    try:
        pwd.getpwnam(USER)
    except KeyError:
        pass
    else:
        raise RuntimeError('OS account already exists')
    assert sql("SELECT COUNT(*) FROM mysql.user WHERE User='vapt_reader';") == '0'
    assert sql("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='nuers_vapt_multi';") == DB
    network = json.loads(run(['docker', 'inspect', 'nuers-multi-db']))[0]['NetworkSettings']['Networks']['bridge']
    assert network['IPAddress'] == '172.17.0.2', 'Unexpected DB container target; review egress guard'
    gateway = str(ipaddress.ip_address(network['Gateway']))
    assert gateway == '172.17.0.1', 'Unexpected Docker bridge gateway'
    db_host = '10.85.0.70'  # Verified source of host SSH-forwarded MariaDB connections
    public_key = (HERE / 'tester.pub').read_text().strip()
    assert public_key.startswith('ssh-ed25519 ') and '\n' not in public_key
    ROOT.mkdir(mode=0o700)
    # Keep Unix password locked; only the dedicated expiring SSH key authenticates.
    run(['useradd', '--create-home', '--shell', '/bin/bash', '--expiredate', '2026-11-24', USER])
    created_user = True
    uid = pwd.getpwnam(USER).pw_uid
    os.chmod('/home/' + USER, 0o700)
    pathlib.Path('/etc/ssh/nuers-vapt-keys').mkdir(mode=0o755, exist_ok=True)
    write('/etc/ssh/nuers-vapt-keys/' + USER,
          'no-agent-forwarding,no-X11-forwarding,no-user-rc,permitopen="10.85.0.70:3306",expiry-time="20261123090000Z" ' + public_key + '\n', 0o644)
    pathlib.Path('/run/sshd').mkdir(mode=0o755, exist_ok=True)
    write('/etc/ssh/sshd_config_nuers_vapt', (HERE / 'sshd_config').read_text())
    run(['/usr/sbin/sshd', '-t', '-f', '/etc/ssh/sshd_config_nuers_vapt'])
    guard = (HERE / 'guard-egress.sh').read_text().replace('__UID__', str(uid))
    write(ROOT / 'guard-egress.sh', guard, 0o700)
    write(ROOT / 'expire.py', (HERE / 'expire.py').read_text(), 0o700)
    write(ROOT / 'db-host', db_host)
    write('/etc/systemd/system/nuers-vapt-tester-ssh.service', f'''[Unit]
Description=Temporary NUERS staging tester SSH
After=network-online.target docker.service
Wants=network-online.target
[Service]
Type=simple
ExecStartPre=/usr/bin/python3 {ROOT}/expire.py --check
ExecStartPre={ROOT}/guard-egress.sh
ExecStartPre=/usr/sbin/sshd -t -f /etc/ssh/sshd_config_nuers_vapt
ExecStart=/usr/sbin/sshd -D -e -f /etc/ssh/sshd_config_nuers_vapt
Restart=on-failure
RestartSec=10
NoNewPrivileges=yes
TasksMax=128
MemoryMax=512M
CPUQuota=50%
[Install]
WantedBy=multi-user.target
''', 0o644)
    write('/etc/systemd/system/nuers-vapt-infra-expiry.service', f'''[Unit]
Description=Revoke temporary NUERS infrastructure tester access
After=docker.service
[Service]
Type=oneshot
ExecStart=/usr/bin/python3 {ROOT}/expire.py
''', 0o644)
    write('/etc/systemd/system/nuers-vapt-infra-expiry.timer', '''[Unit]
Description=Expire NUERS infrastructure tester access
[Timer]
OnCalendar=2026-11-23 09:00:00 UTC
OnBootSec=45s
Persistent=true
AccuracySec=1s
Unit=nuers-vapt-infra-expiry.service
[Install]
WantedBy=timers.target
''', 0o644)
    password = secrets.token_hex(24)
    sql(f"CREATE USER 'vapt_reader'@'{db_host}' IDENTIFIED BY '{password}' WITH MAX_USER_CONNECTIONS 4 MAX_STATEMENT_TIME 10;\nGRANT SELECT, SHOW VIEW ON `{DB}`.* TO 'vapt_reader'@'{db_host}';")
    packet = json.dumps({'ssh_host': 'ssh-vapt.nuers.net', 'ssh_user': USER,
                         'database': DB, 'db_user': 'vapt_reader', 'db_password': password,
                         'db_remote_host': '10.85.0.70', 'db_remote_port': 3306,
                         'expires_at': EXPIRY.isoformat()})
    run(['openssl', 'cms', '-encrypt', '-binary', '-aes-256-cbc', '-outform', 'DER',
         '-out', str(ROOT / 'credentials.cms'), '/srv/nuers-vapt/tester-20261116/handoff.crt'], input=packet)
    os.chmod(ROOT / 'credentials.cms', 0o600)
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'enable', '--now', 'nuers-vapt-infra-expiry.timer'])
    run(['systemctl', 'enable', '--now', 'nuers-vapt-tester-ssh.service'])
    print(json.dumps({'result': 'created', 'user': USER, 'uid': uid, 'db_host_grant': db_host,
                      'service': run(['systemctl', 'is-active', 'nuers-vapt-tester-ssh.service']),
                      'expiry_timer': run(['systemctl', 'is-active', 'nuers-vapt-infra-expiry.timer'])}))

if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        # Fail closed without dumping SQL, secrets, command arguments or environment.
        if created_user:
            subprocess.run(['systemctl', 'stop', 'nuers-vapt-tester-ssh.service'], capture_output=True)
            subprocess.run(['usermod', '--expiredate', '1970-01-02', USER], capture_output=True)
        if created_user and (ROOT / 'db-host').exists():
            try:
                host = str(ipaddress.ip_address((ROOT / 'db-host').read_text().strip()))
                sql(f"ALTER USER IF EXISTS 'vapt_reader'@'{host}' ACCOUNT LOCK;")
            except Exception:
                pass
        print('Provisioning failed closed: ' + type(exc).__name__, file=sys.stderr)
        sys.exit(1)
