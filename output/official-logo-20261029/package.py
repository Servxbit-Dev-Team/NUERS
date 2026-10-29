from pathlib import Path
import hashlib,json,zipfile,re,subprocess
root=Path.cwd();out=root/'output/official-logo-20261029';meta=json.loads((out/'source.json').read_text());paths={}
for base in ['dist/assets','public/build']:
 for p in (root/base).rglob('*'):
  if p.is_file():
   name=str(p.relative_to(root));paths[name]=name
   if base=='dist/assets':paths['assets/'+str(p.relative_to(root/base))]=name
for p in sorted(set(meta['files']+meta['assets'])):
 if p=='index.html':continue
 paths[p]=p
 if p.startswith('public/'):
  name=p.removeprefix('public/');paths[name]=p;paths['dist/'+name]=p
paths['index.html']='dist/index.html';paths['dist/index.html']='dist/index.html'
remote="import sys,json,hashlib,pathlib; r=pathlib.Path('/var/www/vhosts/nuers.net/httpdocs'); paths=json.load(sys.stdin); print(json.dumps({p:hashlib.sha256((r/p).read_bytes()).hexdigest() if (r/p).is_file() else None for p in paths}))"
result=subprocess.run(['ssh','-S','/private/tmp/nuers-deploy-20261028.sock','-o','BatchMode=yes','-o','ConnectTimeout=8','root@vmi2755926.contaboserver.net','python3 -c '+__import__('shlex').quote(remote)],input=json.dumps(list(paths)),capture_output=True,text=True,check=True)
live=json.loads(result.stdout);(out/'live-baseline.json').write_text(json.dumps(live,indent=2))
baseline=json.loads((out/'baseline.json').read_text())
reviewed_drift={'src/pages/auth/login.tsx':hashlib.sha256((out/'live-login-before.tsx').read_bytes()).hexdigest()}
for d in paths:
 if d.startswith(('app/','resources/','scripts/','src/')) and d in baseline and live[d] is not None and live[d]!=baseline[d] and live[d]!=reviewed_drift.get(d) and live[d]!=hashlib.sha256((root/paths[d]).read_bytes()).hexdigest():raise RuntimeError('Unexpected source drift: '+d)
changed={d:s for d,s in paths.items() if live[d]!=hashlib.sha256((root/s).read_bytes()).hexdigest()}
html=(root/'dist/index.html').read_text();assert '/brand/nuers-official-logo.png' in html
entry=re.search(r'src="(/assets/[^\"]+\.js)"',html).group(1)
manifest={'release':'2026-10-29.official-logo.1','version':meta['version'],'entry':entry,'files':{d:hashlib.sha256((root/s).read_bytes()).hexdigest() for d,s in changed.items()},'baseline':{d:live[d] for d in changed}}
(out/'manifest.json').write_text(json.dumps(manifest,indent=2))
with zipfile.ZipFile(out/'nuers-official-logo-20261029.zip','w',zipfile.ZIP_DEFLATED) as z:
 for d,s in changed.items():z.write(root/s,d)
 z.write(out/'manifest.json','manifest.json')
checksum=hashlib.sha256((out/'nuers-official-logo-20261029.zip').read_bytes()).hexdigest();(out/'nuers-official-logo.sha256').write_text(checksum+'\n')
print(json.dumps({'files':len(changed),'bytes':(out/'nuers-official-logo-20261029.zip').stat().st_size,'entry':entry,'sha256':checksum}))
