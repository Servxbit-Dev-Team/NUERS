import boto3,pathlib,json,time,shlex,datetime,base64,gzip
p=pathlib.Path.home()/'nuers-cloud-vapt-20261115';s=boto3.client('ssm',region_name='ap-southeast-1');ec=boto3.client('ec2',region_name='ap-southeast-1');elb=boto3.client('elbv2',region_name='ap-southeast-1');nodes=['i-0694f0738afd178ce','i-0fe793f3af7b0c49b'];tg='arn:aws:elasticloadbalancing:ap-southeast-1:289829301267:targetgroup/nuers-prod-web/09a353254dddbd2a';r={'complete':False,'events':[]}
def note(k,**v):
 r['events'].append({'stage':k,'time':datetime.datetime.now(datetime.timezone.utc).isoformat(),**v});(p/'kernel-cleanup.json').write_text(json.dumps(r,indent=2,default=str));print(k,v,flush=True)
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

cleanup='set -eu\ntest "$(uname -r)" = 7.0.0-1017-aws\npython3 - <<\'CLEAN\'\nimport subprocess,pathlib,re,os\nexpected={\'linux-aws-7.0-headers-7.0.0-1014\',\'linux-aws-7.0-tools-7.0.0-1014\',\'linux-headers-7.0.0-1014-aws\',\'linux-image-7.0.0-1014-aws\',\'linux-modules-7.0.0-1014-aws\',\'linux-tools-7.0.0-1014-aws\'}\nrows=subprocess.check_output([\'dpkg-query\',\'-W\',\'-f=${binary:Package} ${db:Status-Status} ${Version}\\n\',\'linux*\'],text=True).splitlines()\npkgs={x.split()[0]:x.split()[2]for x in rows if len(x.split())==3 and x.split()[1]==\'installed\' and \'7.0.0-1014\' in x.split()[0]}\nassert set(pkgs)==expected,(set(pkgs),expected)\np=pathlib.Path(\'/var/backups/nuers-os-20261115/kernel1014\');p.mkdir(parents=True,exist_ok=True);p.chmod(0o700)\nplan=subprocess.check_output([\'apt-get\',\'-s\',\'remove\',*sorted(pkgs)],text=True);(p/\'remove-plan.txt\').write_text(plan);assert set(re.findall(r\'^Remv (\\S+)\',plan,re.M))==expected\nsubprocess.run([\'apt-get\',\'download\',*[k+\'=\'+v for k,v in sorted(pkgs.items())]],cwd=p,check=True,stdout=subprocess.DEVNULL)\nassert len(list(p.glob(\'*.deb\')))==6\nwith (p/\'remove.log\').open(\'w\') as f:subprocess.run([\'apt-get\',\'remove\',\'-y\',*sorted(pkgs)],env={**os.environ,\'DEBIAN_FRONTEND\':\'noninteractive\'},stdout=f,stderr=f,check=True)\nprint(\'OLD_KERNEL_RECOVERY_SAVED_AND_REMOVED\')\nCLEAN\ntest -f /boot/vmlinuz-7.0.0-1017-aws\ntest -f /boot/initrd.img-7.0.0-1017-aws\nuname -r\n'
verify='set -eu\ntest "$(readlink -f /srv/nuers/current)" = /srv/nuers/releases/production-20261115-readiness1\ntest "$(uname -r)" = 7.0.0-1017-aws\nsystemctl is-active --quiet nginx php8.4-fpm nuers-pdf.socket\nsha256sum -c /var/backups/nuers-os-20261115/env.sha256\ncd /srv/nuers/current\nrunuser -u nuers -- php -d memory_limit=256M /srv/nuers/deployment-checks/native-readiness-20261115.php\nrunuser -u nuers -- php -r \'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $p=app(App\\Services\\DocumentPdf::class)->renderHtml("<html><head></head><body>NUERS synthetic post-upgrade PDF check</body></html>"); if(!str_starts_with($p,"%PDF-"))exit(1);echo "SANDBOXED_PDF_PASS\\n";\'\npro security-status --format json\nuname -r\ngoogle-chrome --version\npython3 - <<\'HTTP\'\nimport urllib.request,urllib.error,json\nchecks={}\nfor path,want in [(\'/\',200),(\'/login\',200),(\'/api/health\',200),(\'/api/auth/mfa/devices\',401),(\'/api/merchant/receipts\',401),(\'/api/locations/countries\',200),(\'/api/locations/cities?country%5B%5D=PH\',422)]:\n req=urllib.request.Request(\'http://127.0.0.1:8080\'+path,headers={\'Host\':\'nuers.net\',\'X-Forwarded-Proto\':\'https\',\'Accept\':\'application/json\'})\n try:x=urllib.request.urlopen(req,timeout=20)\n except urllib.error.HTTPError as e:x=e\n assert x.status==want,(path,x.status);checks[path]=x.status\nprint(json.dumps(checks))\nHTTP\n'
stage_verify='set -eu\ncd /srv/nuers-vapt\ndocker run --rm --network none -e APP_ENV=testing -e APP_TIMEZONE=UTC -e APP_KEY="base64:$(openssl rand -base64 32)" -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e QUEUE_CONNECTION=sync -e NUERS_PDF_CHROME=/usr/local/lib/nuers-pdf/client.py -v /usr/local/lib/nuers-pdf/client.py:/usr/local/lib/nuers-pdf/client.py:ro -v /run/nuers-pdf.sock:/run/nuers-pdf.sock nuers-vapt:20261114-v3 sh -c \'\nset -eu\nrunuser -u www-data -- php -r \'\\\'\'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $p=app(App\\Services\\DocumentPdf::class)->renderHtml("<html><head></head><body>Synthetic patched renderer security test</body></html>"); if(!str_starts_with($p,"%PDF-"))exit(1);echo "SANDBOXED_PDF_PASS\\n";\'\\\'\'\nrunuser -u www-data -- php vendor/bin/phpunit --do-not-cache-result --filter "DocumentPdfRuntimeTest|InvoiceDocumentComplianceTest|MerchantVerificationDocumentTest"\n\' > evidence/os-20261115/pdf-regression.log 2>&1\ncat evidence/os-20261115/pdf-regression.log\nuname -r\ndpkg-query -W linux-image-aws\n'

stage='i-0fd5169e6c271bf76';current=None
try:
 run(stage,cleanup,'staging-kernel-cleanup')
 output=run(stage,stage_verify,'staging-kernel-retest',600)
 assert '25 tests, 495 assertions' in output and 'SANDBOXED_PDF_PASS' in output
 for n in nodes:
  current=n;assert state(next(x for x in nodes if x!=n))=='healthy'
  elb.deregister_targets(TargetGroupArn=tg,Targets=[{'Id':n,'Port':8080}]);wait(n,'unused');note('drained',node=n)
  run(n,cleanup,'kernel-cleanup',600);run(n,verify,'kernel-retest',600)
  elb.register_targets(TargetGroupArn=tg,Targets=[{'Id':n,'Port':8080}]);wait(n,'healthy');note('healthy',node=n)
 r['complete']=True;note('complete')
except Exception as e:
 note('failed-stop-cleanup',node=current,error=str(e));raise
