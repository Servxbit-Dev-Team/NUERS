import boto3,pathlib,json,time,shlex,datetime,base64,gzip
p=pathlib.Path.home()/'nuers-cloud-vapt-20261115';s=boto3.client('ssm',region_name='ap-southeast-1');ec=boto3.client('ec2',region_name='ap-southeast-1');elb=boto3.client('elbv2',region_name='ap-southeast-1');nodes=['i-0694f0738afd178ce','i-0fe793f3af7b0c49b'];tg='arn:aws:elasticloadbalancing:ap-southeast-1:289829301267:targetgroup/nuers-prod-web/09a353254dddbd2a';r={'complete':False,'events':[]}
def note(k,**v):
 r['events'].append({'stage':k,'time':datetime.datetime.now(datetime.timezone.utc).isoformat(),**v});(p/'os-rollout.json').write_text(json.dumps(r,indent=2,default=str));print(k,v,flush=True)
def run(n,cmd,label,timeout=600):
 cid=s.send_command(InstanceIds=[n],DocumentName='AWS-RunShellScript',Parameters={'commands':[cmd],'executionTimeout':[str(timeout)]},TimeoutSeconds=timeout,Comment='Approved OS remediation; rolling health gate; no account or database changes')['Command']['CommandId'];note(label+'-start',node=n,command=cid)
 for _ in range(timeout//3+10):
  try:q=s.get_command_invocation(CommandId=cid,InstanceId=n)
  except s.exceptions.InvocationDoesNotExist:time.sleep(3);continue
  if q['Status']not in ['Pending','InProgress','Delayed']:break
  time.sleep(3)
 (p/(label+'-'+n+'.json')).write_text(json.dumps(q,default=str));assert q['Status']=='Success',q.get('StandardErrorContent','')[-1000:];note(label+'-pass',node=n,output=q['StandardOutputContent'][-1500:]);return q['StandardOutputContent']
def state(n):return elb.describe_target_health(TargetGroupArn=tg,Targets=[{'Id':n,'Port':8080}])['TargetHealthDescriptions'][0]['TargetHealth']['State']
def wait(n,want):
 for _ in range(120):
  if state(n)==want:return
  time.sleep(5)
 raise RuntimeError('Target health timeout '+n+' '+want)
# Guard against applying before the actual patched-kernel staging regression.
gate=json.loads((p/'staging-os-test.json').read_text());out=gate['StandardOutputContent'];assert gate['Status']=='Success' and '7.0.0-1017-aws' in out and '25 tests, 495 assertions' in out and 'SANDBOXED_PDF_PASS' in out
py=json.loads((p/'python-runtime.json').read_text())
for n in nodes:
 assert py[n]['result']['venvs']['out']=='' and not py[n]['result']['bootstrap_references']
 assert state(n)=='healthy'
pre=r'''set -eu
test "$(readlink -f /srv/nuers/current)" = /srv/nuers/releases/production-20261115-readiness1
systemctl is-active --quiet nginx php8.4-fpm nuers-pdf.socket
mkdir -p /var/backups/nuers-os-20261115
chmod 700 /var/backups/nuers-os-20261115
# Retain exact Chrome files and package state for recovery; no customer records copied.
test -e /var/backups/nuers-os-20261115/chrome-before.tar.gz || tar -czf /var/backups/nuers-os-20261115/chrome-before.tar.gz -C /opt/google chrome
dpkg-query -W > /var/backups/nuers-os-20261115/packages-before.txt
sha256sum /srv/nuers/current/.env > /var/backups/nuers-os-20261115/env.sha256
apt-get update -qq
apt-get -s remove python3-pip-whl > /var/backups/nuers-os-20261115/remove-plan.txt
python3 - <<'CHECK'
from pathlib import Path
import re
s=Path('/var/backups/nuers-os-20261115/remove-plan.txt').read_text();assert set(re.findall(r'^Remv (\S+)',s,re.M))=={'python3-pip-whl','python3-venv','python3.12-venv'}
CHECK
cd /var/backups/nuers-os-20261115
apt-get download google-chrome-stable=157.0.8103.78-1 >/dev/null 2>&1
apt-get download python3-pip-whl=24.0+dfsg-1ubuntu1.3 python3-venv=3.12.3-0ubuntu2.1 python3.12-venv=3.12.3-1ubuntu0.17 >/dev/null 2>&1
printf 'PREPARED\n'
'''
change=r'''set -eu
cd /var/backups/nuers-os-20261115
DEBIAN_FRONTEND=noninteractive apt-get install -y --only-upgrade google-chrome-stable=157.0.8103.78-1 > chrome-update.log 2>&1
DEBIAN_FRONTEND=noninteractive apt-get remove -y python3-pip-whl python3-venv python3.12-venv > remove-bootstrap.log 2>&1
sha256sum -c env.sha256
cat /proc/sys/kernel/random/boot_id
google-chrome --version
'''
verify=r'''set -eu
test "$(readlink -f /srv/nuers/current)" = /srv/nuers/releases/production-20261115-readiness1
test "$(uname -r)" = 7.0.0-1017-aws
systemctl is-active --quiet nginx php8.4-fpm nuers-pdf.socket
sha256sum -c /var/backups/nuers-os-20261115/env.sha256
cd /srv/nuers/current
runuser -u nuers -- php -d memory_limit=256M /srv/nuers/deployment-checks/native-readiness-20261115.php
runuser -u nuers -- php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $p=app(App\Services\DocumentPdf::class)->renderHtml("<html><head></head><body>NUERS synthetic post-upgrade PDF check</body></html>"); if(!str_starts_with($p,"%PDF-"))exit(1);echo "SANDBOXED_PDF_PASS\n";'
pro security-status --format json
uname -r
google-chrome --version
python3 - <<'HTTP'
import urllib.request,urllib.error,json
checks={}
for path,want in [('/',200),('/login',200),('/api/health',200),('/api/auth/mfa/devices',401),('/api/merchant/receipts',401),('/api/locations/countries',200),('/api/locations/cities?country%5B%5D=PH',422)]:
 req=urllib.request.Request('http://127.0.0.1:8080'+path,headers={'Host':'nuers.net','X-Forwarded-Proto':'https','Accept':'application/json'})
 try:x=urllib.request.urlopen(req,timeout=20)
 except urllib.error.HTTPError as e:x=e
 assert x.status==want,(path,x.status);checks[path]=x.status
print(json.dumps(checks))
HTTP
'''
current=None
try:
 for n in nodes:run(n,pre,'prepare')
 for n in nodes:
  current=n;assert state(next(x for x in nodes if x!=n))=='healthy';elb.deregister_targets(TargetGroupArn=tg,Targets=[{'Id':n,'Port':8080}]);note('drain',node=n);wait(n,'unused');run(n,change,'update');ec.reboot_instances(InstanceIds=[n]);note('reboot',node=n);time.sleep(20)
  # SSM reads are retried only until a real new kernel is available.
  ready=False
  for _ in range(24):
   try:
    z=run(n,'uname -r','boot-probe',60)
    if z.strip()=='7.0.0-1017-aws':ready=True;break
   except Exception:pass
   time.sleep(10)
  assert ready,'New kernel not confirmed'
  run(n,verify,'verify',600);elb.register_targets(TargetGroupArn=tg,Targets=[{'Id':n,'Port':8080}]);wait(n,'healthy');note('healthy',node=n)
 r['complete']=True;note('complete',targets={n:state(n)for n in nodes})
except Exception as e:
 note('failed-stop-rollout',node=current,error=str(e));raise
