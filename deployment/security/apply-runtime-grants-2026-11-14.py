# CloudShell only: read metadata and DB grants through an SSM port-forward.
# The managed password stays in memory; no credential file, CLI argument or output.
import boto3,json,pathlib,subprocess,socket,time,sys,urllib.request,importlib,os,signal
p=pathlib.Path.home()/'nuers-vapt-20261114'
assert json.loads((p/'production-rollout.json').read_text())['complete'] is True

sys.path.insert(0,str(p/'python-deps'))
try:import pymysql
except ImportError:
 subprocess.run([sys.executable,'-m','pip','install','--target',str(p/'python-deps'),'--quiet','pymysql'],check=True)
 importlib.invalidate_caches()
 import pymysql
rds=boto3.client('rds',region_name='ap-southeast-1').describe_db_instances(DBInstanceIdentifier='nuers-prod')['DBInstances'][0]
print('RDS',json.dumps({k:rds[k] for k in ['DBInstanceIdentifier','DBInstanceClass','MultiAZ','StorageEncrypted']}))
host=rds['Endpoint']['Address'];port=13317
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
  cur.execute("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB='nuers_rehearsal_20261106' AND USER='nuers_runtime'")
  assert cur.fetchone()[0]==0,'Active rehearsal consumer requires separate identity before revocation'
  cur.execute("SELECT TABLE_SCHEMA,PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=\"'nuers_runtime'@'%'\" ORDER BY TABLE_SCHEMA,PRIVILEGE_TYPE")
  before=cur.fetchall()
  rehearsal=[x for x in before if x[0]=='nuers_rehearsal_20261106']
  if rehearsal:
   assert {x[1] for x in rehearsal}=={'SELECT','INSERT','UPDATE','DELETE'}
   cur.execute("REVOKE SELECT, INSERT, UPDATE, DELETE ON `nuers_rehearsal_20261106`.* FROM 'nuers_runtime'@'%'")
   conn.commit()
  cur.execute("SELECT TABLE_SCHEMA,PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=\"'nuers_runtime'@'%'\" ORDER BY TABLE_SCHEMA,PRIVILEGE_TYPE")
  after=cur.fetchall()
  assert len(after)==4 and {x[0] for x in after}=={'nuers_production_20261106'} and {x[1] for x in after}=={'SELECT','INSERT','UPDATE','DELETE'} and {x[2] for x in after}=={'NO'}
  cur.execute("SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE=\"'nuers_runtime'@'%'\"")
  global_grants=cur.fetchall();assert all(x[0]=='USAGE' for x in global_grants)
  cur.execute("SELECT TABLE_SCHEMA,TABLE_NAME,PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=\"'nuers_runtime'@'%'\"")
  table_grants=cur.fetchall();assert not table_grants,'Unexpected per-table grants need review'
  result={'before':before,'after':after,'global_grants':global_grants,'active_rehearsal_consumers':0,'applied':True,'no_new_identity_created':True}
  (p/'production-runtime-grants.json').write_text(json.dumps(result,indent=2));print('PRODUCTION_RUNTIME_GRANTS',json.dumps(result))
 conn.close()
finally:
 os.killpg(tunnel.pid,signal.SIGTERM)
 try:tunnel.wait(timeout=10)
 except subprocess.TimeoutExpired:os.killpg(tunnel.pid,signal.SIGKILL)
 log.close()
