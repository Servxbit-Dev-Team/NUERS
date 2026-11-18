#!/usr/bin/env python3
"""Node A only; synthetic database + ephemeral local CA. Never reads production data."""
import base64,json,os,secrets,subprocess,time
from pathlib import Path
ROOT=Path('/srv/nuers-vapt');PRIVATE=ROOT/'private';PRIVATE.mkdir(exist_ok=True,mode=0o700)
os.umask(0o077)
def run(args,**kwargs):
 result=subprocess.run(args,check=False,**kwargs)
 if result.returncode: raise RuntimeError('Synthetic staging command failed (arguments withheld)')
 return result
def capture(args):return subprocess.check_output(args,text=True)
assert capture(['hostname','-I']).split()[0]=='10.85.0.70'
existing=subprocess.run(['docker','inspect','nuers-multi-db'],capture_output=True).returncode==0
password=(PRIVATE/'db.env').read_text().strip().split('=',1)[1] if existing else secrets.token_hex(32)
runtime=secrets.token_hex(32)
if (PRIVATE/'runtime.env').exists(): runtime=dict(l.split('=',1) for l in (PRIVATE/'runtime.env').read_text().splitlines())['DB_PASSWORD']
if not existing:
 (PRIVATE/'db.env').write_text('MARIADB_ROOT_PASSWORD='+password+'\n')
 run(['docker','run','-d','--name','nuers-multi-db','--env-file',str(PRIVATE/'db.env'),'-p','10.85.0.70:3306:3306','-v',str(ROOT/'mysql-multi')+':/var/lib/mysql','mariadb:10.11.19','--event-scheduler=OFF'])
for i in range(60):
 r=subprocess.run(['docker','exec','-e','MYSQL_PWD='+password,'nuers-multi-db','mariadb','-uroot','--batch','--execute','SELECT 1'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 if r.returncode==0:break
 time.sleep(2)
else:raise RuntimeError('Synthetic DB startup failed')
def sql(text):
 return run(['docker','exec','-i','-e','MYSQL_PWD='+password,'nuers-multi-db','mariadb','-uroot'],input=text,text=True,capture_output=True)
sql("CREATE DATABASE IF NOT EXISTS nuers_vapt_multi; CREATE DATABASE IF NOT EXISTS nuers_vapt_forbidden; CREATE USER IF NOT EXISTS 'nuers_stage'@'%' IDENTIFIED BY '"+runtime+"'; GRANT SELECT,INSERT,UPDATE,DELETE ON nuers_vapt_multi.* TO 'nuers_stage'@'%';")
config={'APP_ENV':'staging','APP_TIMEZONE':'UTC','APP_DEBUG':'false','APP_KEY':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),'APP_URL':'https://10.85.0.70:8080','DB_CONNECTION':'mysql','DB_HOST':'10.85.0.70','DB_PORT':'3306','DB_DATABASE':'nuers_vapt_multi','DB_USERNAME':'nuers_stage','DB_PASSWORD':runtime,'MAIL_MAILER':'array','QUEUE_CONNECTION':'database','CACHE_STORE':'database','SESSION_DRIVER':'database','NUERS_NOTIFICATION_DISPATCH_ENABLED':'false','NUERS_MERCHANT_WEBHOOK_DELIVERY_ENABLED':'false','NUERS_INFRA_CONTROLLER_ENABLED':'false','NUERS_INFRA_EXECUTION_ENABLED':'false','NUERS_INFRA_BACKUP_ENABLED':'false'}
def envfile(path,values):path.write_text(''.join(k+'='+v+'\n' for k,v in values.items()))
envfile(PRIVATE/'runtime.env',config)
# Migrator is confined to disposable test database, removed immediately after migration/seed.
migrator=secrets.token_hex(32)
sql("CREATE USER 'nuers_migrate'@'%' IDENTIFIED BY '"+migrator+"'; GRANT ALL PRIVILEGES ON nuers_vapt_multi.* TO 'nuers_migrate'@'%';")
envfile(PRIVATE/'migrate.env',dict(config,DB_USERNAME='nuers_migrate',DB_PASSWORD=migrator))
try:
 run(['docker','run','--rm','--env-file',str(PRIVATE/'migrate.env'),'nuers-vapt:20261114','php','artisan','migrate','--force'],stdout=open(ROOT/'evidence/migrations.log','w'),stderr=subprocess.STDOUT)
 with (PRIVATE/'fixtures.json').open('w') as f:
  run(['docker','run','--rm','--env-file',str(PRIVATE/'migrate.env'),'-v',str(ROOT/'harness/synthetic-fixtures.php')+':/app/deployment/staging/synthetic-fixtures.php:ro','nuers-vapt:20261114','php','deployment/staging/synthetic-fixtures.php','seed'],stdout=f)
finally:
 sql("DROP USER 'nuers_migrate'@'%';");(PRIVATE/'migrate.env').unlink()
# Local CA trusted only by this test driver; no TLS bypass.
run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=NUERS Synthetic Staging CA','-keyout',str(PRIVATE/'ca.key'),'-out',str(PRIVATE/'ca.crt')],stderr=subprocess.DEVNULL)
run(['openssl','req','-newkey','rsa:2048','-nodes','-subj','/CN=NUERS Synthetic Staging','-keyout',str(PRIVATE/'tls.key'),'-out',str(PRIVATE/'tls.csr')],stderr=subprocess.DEVNULL)
(PRIVATE/'tls.ext').write_text('subjectAltName=IP:10.85.0.70,IP:10.85.0.208\nextendedKeyUsage=serverAuth\n')
run(['openssl','x509','-req','-in',str(PRIVATE/'tls.csr'),'-CA',str(PRIVATE/'ca.crt'),'-CAkey',str(PRIVATE/'ca.key'),'-CAcreateserial','-days','1','-extfile',str(PRIVATE/'tls.ext'),'-out',str(PRIVATE/'tls.crt')],stderr=subprocess.DEVNULL)
# Export only fresh synthetic runtime config/certificate to the approved staging prefix, never fixtures/admin password/CA private key.
import boto3,tarfile
with tarfile.open(PRIVATE/'peer.tar.gz','w:gz') as t:
 for name in ['runtime.env','tls.crt','tls.key','ca.crt']:t.add(PRIVATE/name,arcname=name)
boto3.client('s3',region_name='ap-southeast-1').upload_file(str(PRIVATE/'peer.tar.gz'),'nuers-289829301267-staging-documents-sg','vapt-20261114/results/peer.tar.gz')
print('SYNTHETIC_MULTI_DATABASE_READY')
