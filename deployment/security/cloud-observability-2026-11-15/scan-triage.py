import boto3,time,pathlib,json,shlex,base64,gzip,hashlib
p=pathlib.Path.home()/'nuers-cloud-vapt-20261115';s=boto3.client('ssm',region_name='ap-southeast-1');s3=boto3.client('s3',region_name='ap-southeast-1');stage='i-0fd5169e6c271bf76';bucket='nuers-289829301267-staging-documents-sg';key='vapt-20261114/os-20261115/package-inventory.json'
def run(n,cmd,label,timeout=600):
 cid=s.send_command(InstanceIds=[n],DocumentName='AWS-RunShellScript',Parameters={'commands':[cmd],'executionTimeout':[str(timeout)]},TimeoutSeconds=timeout,Comment='Read-only OS package inventory scan; no application files or customer data')['Command']['CommandId']
 for _ in range(timeout//3+10):
  try:q=s.get_command_invocation(CommandId=cid,InstanceId=n)
  except s.exceptions.InvocationDoesNotExist:time.sleep(3);continue
  if q['Status']not in ['Pending','InProgress','Delayed']:break
  time.sleep(3)
 (p/(label+'.json')).write_text(json.dumps(q,default=str));assert q['Status']=='Success',q.get('StandardErrorContent','')[-1000:];return q['StandardOutputContent']

code="import json,collections,gzip,base64\nr=json.load(open('/srv/nuers-vapt/evidence/os-inventory-20261115/summary.json'))\nfor n,d in r['nodes'].items():\n v=d.pop('findings');g={}\n for x in v:\n  k=x['PkgName'];z=g.setdefault(k,{'installed':x['InstalledVersion'],'fixed_versions':set(),'severity':collections.Counter(),'status':collections.Counter()});z['fixed_versions'].add(x.get('FixedVersion') or '');z['severity'][x['Severity']]+=1;z['status'][x['Status']]+=1\n for z in g.values():z['fixed_versions']=sorted(z['fixed_versions'])\n d['by_package']=g;d['unfixed_critical_high']=[x for x in v if x['Severity'] in ['CRITICAL','HIGH'] and not x.get('FixedVersion')][:20]\nprint(base64.b64encode(gzip.compress(json.dumps(r).encode())).decode())\n"
out=run(stage,"python3 -c "+shlex.quote(code),"scan-triage")
(p/"os-scan-triage.json").write_bytes(gzip.decompress(base64.b64decode(out.strip())))
print("TRIAGE_SAVED")
