import boto3,json,pathlib,time,datetime
p=pathlib.Path.home()/'nuers-input-20261115';region='ap-southeast-1';s=boto3.client('ssm',region_name=region);e=boto3.client('elbv2',region_name=region)
g='arn:aws:elasticloadbalancing:ap-southeast-1:289829301267:targetgroup/nuers-prod-web/09a353254dddbd2a'
nodes=['i-0694f0738afd178ce','i-0fe793f3af7b0c49b'];base='/srv/nuers/releases/production-20261115-frontend1';new='/srv/nuers/releases/production-20261115-input1'
report={'started_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'base':base,'candidate':new,'events':[],'complete':False}
def note(stage,**kw):
 event={'time':datetime.datetime.now(datetime.timezone.utc).isoformat(),'stage':stage,**kw};report['events'].append(event);(p/'production-rollout.json').write_text(json.dumps(report,indent=2));print(json.dumps(event),flush=True)
def state(node):
 return e.describe_target_health(TargetGroupArn=g,Targets=[{'Id':node,'Port':8080}])['TargetHealthDescriptions'][0]['TargetHealth']['State']
def waitstate(node,desired):
 for _ in range(90):
  if state(node)==desired:return
  time.sleep(4)
 raise RuntimeError('Target did not become '+desired)
def run(node,cmd,label):
 c=s.send_command(InstanceIds=[node],DocumentName='AWS-RunShellScript',Parameters={'commands':[cmd],'executionTimeout':['180']})['Command']['CommandId'];note(label,node=node,command_id=c)
 for _ in range(90):
  try:q=s.get_command_invocation(CommandId=c,InstanceId=node)
  except s.exceptions.InvocationDoesNotExist:time.sleep(2);continue
  if q['Status'] not in ['Pending','InProgress','Delayed']:
   (p/(label+'-'+node+'.json')).write_text(json.dumps(q,default=str));assert q['Status']=='Success',label+' failed: '+q['StandardErrorContent'][-600:];return
  time.sleep(2)
 raise RuntimeError(label+' timeout')
# Preconditions: exact scoped preparation and native synthetic PDF probe on both nodes.
for n in nodes:
 q=s.get_command_invocation(CommandId=json.loads((p/'prepare-command.json').read_text())['command_id'],InstanceId=n)
 assert q['Status']=='Success' and q['ResponseCode']==0,'Candidate probe must pass on both nodes'
 q=s.get_command_invocation(CommandId=json.loads((p/'native-retest-command.json').read_text())['command_id'],InstanceId=n)
 assert q['Status']=='Success' and '"native_input_probe":"passed"' in q['StandardOutputContent'],'Native input assertions must pass'
 assert state(n)=='healthy','Both targets must initially be healthy'
# No migration: the current backend and database are preserved.
changed=[];current=None
try:
 for n in nodes:
  current=n;other=next(x for x in nodes if x!=n);assert state(other)=='healthy'
  e.deregister_targets(TargetGroupArn=g,Targets=[{'Id':n,'Port':8080}]);note('draining',node=n);waitstate(n,'unused');note('drained',node=n)
  cmd='''set -eu
[ "$(readlink -f /srv/nuers/current)" = "'''+base+'''" ]
python3 - <<'SWITCH'
import os
p='/srv/nuers/current.vapt-next'
assert not os.path.lexists(p)
os.symlink('''+repr(new)+''',p);os.replace(p,'/srv/nuers/current')
SWITCH
systemctl reload php8.4-fpm
if systemctl is-active --quiet nuers-notifications.service; then systemctl restart nuers-notifications.service; fi
cd /srv/nuers/current
runuser -u nuers -- php /srv/nuers/deployment-checks/input-probe-20261115.php
runuser -u nuers -- php /srv/nuers/deployment-checks/native-input-probe-20261115.php | tee /tmp/nuers-input-native-result.json
grep -q '"native_input_probe":"passed"' /tmp/nuers-input-native-result.json
python3 - <<'HTTP'
import urllib.request,urllib.error,json,pathlib,hashlib
base='http://127.0.0.1:8080';checks={}
for path,want in [('/infrastructure-health',200),('/',200),('/login',200),('/api/health',200),('/api/tables/merchants',401)]:
 req=urllib.request.Request(base+path,headers={'Host':'nuers.net','X-Forwarded-Proto':'https','Accept':'application/json' if path.startswith('/api/') else 'text/html'})
 try:r=urllib.request.urlopen(req,timeout=30)
 except urllib.error.HTTPError as er:r=er
 body=r.read();checks[path]=r.status;assert r.status==want,(path,r.status)
 assert 'PHP' not in r.headers.get('X-Powered-By','')
manifest=json.loads(pathlib.Path('public/build/manifest.json').read_text());entry=next(v for k,v in manifest.items() if v.get('isEntry') and v.get('file','').endswith('.js'))
for f in [entry['file']]+entry.get('css',[]):
 raw=urllib.request.urlopen(urllib.request.Request(base+'/build/'+f,headers={'Host':'nuers.net','X-Forwarded-Proto':'https'}),timeout=30).read()
 assert hashlib.sha256(raw).digest()==hashlib.sha256(pathlib.Path('public/build',f).read_bytes()).digest()
print('LIVE_HTTP_CHECKS',json.dumps(checks));print('LIVE_ASSET_HASHES_MATCH')
HTTP
systemctl is-active --quiet php8.4-fpm nginx
echo LIVE_RELEASE_SMOKE_PASS
'''
  run(n,cmd,'switch-smoke');changed.append(n)
  e.register_targets(TargetGroupArn=g,Targets=[{'Id':n,'Port':8080}]);note('registering',node=n);waitstate(n,'healthy');note('healthy',node=n)
 report['complete']=True;note('rollout_complete',targets={n:state(n) for n in nodes})
except Exception as error:
 note('rollout_failed',error=str(error))
 # Restore affected pointers to the preserved release; no database changes were made.
 for n in list(dict.fromkeys(([current] if current else [])+list(reversed(changed)))):
  try:
   other=next(x for x in nodes if x!=n)
   assert state(other)=='healthy','Preserve current serving target until its peer is healthy'
   e.deregister_targets(TargetGroupArn=g,Targets=[{'Id':n,'Port':8080}]);waitstate(n,'unused')
   rollback="set -eu\npython3 -c \"import os; p='/srv/nuers/current.vapt-rollback'; assert not os.path.lexists(p); os.symlink('"+base+"',p); os.replace(p,'/srv/nuers/current')\"\nsystemctl reload php8.4-fpm\nif systemctl is-active --quiet nuers-notifications.service; then systemctl restart nuers-notifications.service; fi\ncurl --fail --silent http://127.0.0.1:8080/infrastructure-health >/dev/null"
   run(n,rollback,'rollback');e.register_targets(TargetGroupArn=g,Targets=[{'Id':n,'Port':8080}]);waitstate(n,'healthy');note('rollback_healthy',node=n)
  except Exception as rollback_error:note('rollback_needs_attention',node=n,error=str(rollback_error))
 raise
