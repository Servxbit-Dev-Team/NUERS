# CloudShell: approved additive migration through the separate deployment identity.
# The managed password stays in memory; no credential file, CLI argument or output.
import boto3,json,pathlib,subprocess,socket,time,sys,urllib.request,importlib,os,signal
p=pathlib.Path.home()/'nuers-vapt-20261114'
ssm=boto3.client('ssm',region_name='ap-southeast-1')
for node in ['i-0694f0738afd178ce','i-0fe793f3af7b0c49b']:
 q=ssm.get_command_invocation(CommandId='cca7f073-8910-4dc9-921b-1cededfed5a9',InstanceId=node)
 assert q['Status']=='Success' and 'INACTIVE_RELEASE_PREPARED' in q['StandardOutputContent']

sys.path.insert(0,str(p/'python-deps'))
try:import pymysql
except ImportError:
 subprocess.run([sys.executable,'-m','pip','install','--target',str(p/'python-deps'),'--quiet','pymysql'],check=True)
 importlib.invalidate_caches()
 import pymysql
rds=boto3.client('rds',region_name='ap-southeast-1').describe_db_instances(DBInstanceIdentifier='nuers-prod')['DBInstances'][0]
print('RDS',json.dumps({k:rds[k] for k in ['DBInstanceIdentifier','DBInstanceClass','MultiAZ','StorageEncrypted']}))
host=rds['Endpoint']['Address'];port=13316
sock=socket.socket();busy=sock.connect_ex(('127.0.0.1',port))==0;sock.close()
if busy:raise RuntimeError('Audit port already occupied; refusing unknown listener')
log=(p/'rds-tunnel.log').open('w')
tunnel=subprocess.Popen(['aws','ssm','start-session','--region','ap-southeast-1','--target','i-0694f0738afd178ce','--document-name','AWS-StartPortForwardingSessionToRemoteHost','--parameters',json.dumps({'host':[host],'portNumber':['3306'],'localPortNumber':[str(port)]})],stdin=subprocess.DEVNULL,stdout=log,stderr=subprocess.STDOUT,start_new_session=True)
try:
 for _ in range(30):
  sock=socket.socket();ready=sock.connect_ex(('127.0.0.1',port))==0;sock.close()
  if ready:break
  if tunnel.poll() is not None:raise RuntimeError('SSM tunnel exited; see metadata-only tunnel log')
  time.sleep(1)
 else:raise RuntimeError('SSM tunnel unavailable')
 arn=rds['MasterUserSecret']['SecretArn']
 credentials=json.loads(boto3.client('secretsmanager',region_name='ap-southeast-1').get_secret_value(SecretId=arn)['SecretString'])
 ca=p/'rds-ca.pem'
 ca.write_bytes(urllib.request.urlopen('https://truststore.pki.rds.amazonaws.com/ap-southeast-1/ap-southeast-1-bundle.pem',timeout=20).read())
 conn=pymysql.connect(host=host,port=3306,user=credentials['username'],password=credentials['password'],connect_timeout=15,read_timeout=15,write_timeout=15,ssl_ca=str(ca),ssl_verify_cert=True,ssl_verify_identity=True,defer_connect=True)
 conn.connect(sock=socket.create_connection(('127.0.0.1',port),timeout=15))
 credentials.clear()
 with conn.cursor() as cur:
  cur.execute("SELECT VERSION()")
  version=cur.fetchone()[0]
  cur.execute("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='nuers_production_20261106' AND TABLE_NAME='users' AND COLUMN_NAME IN ('api_last_activity_at','api_mfa_challenged_at')")
  existing=[row[0] for row in cur.fetchall()]
  name='2026_11_14_000001_add_privileged_session_policy'
  cur.execute("SELECT COUNT(*) FROM nuers_production_20261106.migrations WHERE migration=%s",(name,))
  recorded=cur.fetchone()[0]
  if existing or recorded:
   assert len(existing)==2 and recorded==1,'Partial or unknown migration state; stop for inspection'
   print('ADDITIVE_MIGRATION_ALREADY_APPLIED')
  else:
   cur.execute("SET SESSION lock_wait_timeout=10")
   cur.execute("ALTER TABLE nuers_production_20261106.users ADD COLUMN api_last_activity_at TIMESTAMP NULL DEFAULT NULL, ADD COLUMN api_mfa_challenged_at TIMESTAMP NULL DEFAULT NULL, ALGORITHM=INSTANT")
   cur.execute("SELECT COALESCE(MAX(batch),0)+1 FROM nuers_production_20261106.migrations")
   batch=cur.fetchone()[0]
   cur.execute("INSERT INTO nuers_production_20261106.migrations (migration,batch) VALUES (%s,%s)",(name,batch))
   conn.commit()
   print('ADDITIVE_MIGRATION_APPLIED')
  cur.execute("SELECT COLUMN_NAME,IS_NULLABLE,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='nuers_production_20261106' AND TABLE_NAME='users' AND COLUMN_NAME IN ('api_last_activity_at','api_mfa_challenged_at') ORDER BY COLUMN_NAME")
  result={'version':version,'migration':name,'columns':cur.fetchall(),'method':'separate managed operator; verified TLS; instant additive DDL','customer_records_rewritten':False}
  (p/'production-migration.json').write_text(json.dumps(result,indent=2));print(json.dumps(result))
 conn.close()
finally:
 os.killpg(tunnel.pid,signal.SIGTERM)
 try:tunnel.wait(timeout=10)
 except subprocess.TimeoutExpired:os.killpg(tunnel.pid,signal.SIGKILL)
 log.close()
