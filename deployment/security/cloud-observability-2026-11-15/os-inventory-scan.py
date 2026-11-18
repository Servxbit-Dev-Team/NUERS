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
for _ in range(100):
 r=json.loads((p/'kernel-cleanup.json').read_text())
 if r['complete']:break
 if r['events'][-1]['stage']=='failed-stop-cleanup':raise RuntimeError('Rollout failed')
 time.sleep(5)
assert r['complete']
code=r'''import pathlib,subprocess,gzip,base64,json
fmt='Package: ${binary:Package}\nStatus: ${Status}\nVersion: ${Version}\nArchitecture: ${Architecture}\nSource: ${source:Package} (${source:Version})\n\n'
r={'status':subprocess.check_output(['dpkg-query','-W','-f='+fmt],text=True),'os_release':pathlib.Path('/etc/os-release').read_text(),'debian_version':pathlib.Path('/etc/debian_version').read_text(),'lsb_release':pathlib.Path('/etc/lsb-release').read_text()};print(base64.b64encode(gzip.compress(json.dumps(r).encode())).decode())'''
inventories={}
for n in ['i-0694f0738afd178ce','i-0fe793f3af7b0c49b']:
 inventories[n]=json.loads(gzip.decompress(base64.b64decode(run(n,'python3 -c '+shlex.quote(code),'inventory-'+n).strip())))
 assert inventories[n]['status'].count('Package: ')>800
(p/'package-inventory.json').write_text(json.dumps(inventories))
cmd=r'''set -eu
mkdir -p /srv/nuers-vapt/evidence/os-inventory-20261115
cd /srv/nuers-vapt/evidence/os-inventory-20261115
python3 - <<'SETUP'
import boto3,json,pathlib
import gzip,base64
pathlib.Path('package-inventory.json').write_bytes(gzip.decompress(base64.b64decode('INVENTORY_PAYLOAD')))
for n,v in json.load(open('package-inventory.json')).items():
 p=pathlib.Path(n);(p/'var/lib/dpkg').mkdir(parents=True,exist_ok=True);(p/'etc').mkdir(exist_ok=True);(p/'usr/lib').mkdir(parents=True,exist_ok=True);(p/'var/lib/dpkg/status').write_text(v['status']);(p/'etc/os-release').write_text(v['os_release']);(p/'usr/lib/os-release').write_text(v['os_release']);(p/'etc/debian_version').write_text(v['debian_version']);(p/'etc/lsb-release').write_text(v['lsb_release'])
SETUP
docker pull ghcr.io/aquasecurity/trivy:0.75.0 > pull.log 2>&1
docker image inspect ghcr.io/aquasecurity/trivy:0.75.0 --format '{{json .RepoDigests}}' > scanner-digest.json
for n in i-0694f0738afd178ce i-0fe793f3af7b0c49b; do
 docker run --rm --cpus=2 --memory=2g --security-opt=no-new-privileges --cap-drop=ALL -v "$PWD/$n:/scan:ro" -v "$PWD:/out" -v "$PWD/cache:/root/.cache" ghcr.io/aquasecurity/trivy:0.75.0 rootfs --scanners vuln --pkg-types os --list-all-pkgs --format json --output "/out/$n.json" /scan > "$n.log" 2>&1
done
python3 - <<'REPORT'
import json,pathlib,collections,boto3
r={'scanner_digest':json.load(open('scanner-digest.json')),'scope':'OS package inventory only; no application files; third-party vendor advisories assessed separately','nodes':{}}
for n in ['i-0694f0738afd178ce','i-0fe793f3af7b0c49b']:
 d=json.load(open(n+'.json'));v=[x for a in d.get('Results',[])for x in a.get('Vulnerabilities',[])];pkgs=[x for a in d.get('Results',[])for x in a.get('Packages',[])];assert d.get('Metadata',{}).get('OS',{}).get('Family')=='ubuntu', 'Invalid OS detection'
 assert len(pkgs)>800, 'Invalid coverage: fewer than 800 packages detected'
 r['nodes'][n]={'metadata':d.get('Metadata'),'packages_scanned':len(pkgs),'severity':dict(collections.Counter(x.get('Severity')for x in v)),'fixable':sum(bool(x.get('FixedVersion'))for x in v),'findings':[{k:x.get(k)for k in ['VulnerabilityID','PkgName','InstalledVersion','FixedVersion','Severity','Status','PrimaryURL']}for x in v]}
json.dump(r,open('summary.json','w'),indent=2);print(json.dumps({n:{k:v for k,v in d.items() if k not in ['findings','metadata']}for n,d in r['nodes'].items()}))
REPORT
'''
cmd=cmd.replace('INVENTORY_PAYLOAD',base64.b64encode(gzip.compress(json.dumps(inventories).encode())).decode())
result=run(stage,cmd,'inventory-scan',900);print(result)
compact="import json,pathlib,collections,gzip,base64\nr=json.load(open('/srv/nuers-vapt/evidence/os-inventory-20261115/summary.json'))\nfor n,d in r['nodes'].items():\n v=d.pop('findings');d['unique_cves']=len({x['VulnerabilityID'] for x in v});d['fixable_packages']=sorted({x['PkgName'] for x in v if x.get('FixedVersion')});d['high_critical_by_package']={}\n for x in v:\n  if x['Severity'] in ['HIGH','CRITICAL']:d['high_critical_by_package'].setdefault(x['PkgName'],[]).append(x['VulnerabilityID'])\nprint(base64.b64encode(gzip.compress(json.dumps(r).encode())).decode())\n"
encoded=run(stage,"python3 -c "+shlex.quote(compact),"inventory-summary")
(p/"os-scan-summary.json").write_bytes(gzip.decompress(base64.b64decode(encoded.strip())))
print("INVENTORY_SCAN_COMPLETE")
