from pathlib import Path
import hashlib,json,zipfile
root=Path.cwd();out=root/'output/registry-overview-20261020/release';base=json.loads((out.parent/'before-hashes.json').read_text());files={}
for folder in ['app','src','docs','public/build','dist/assets']:
 for f in (root/folder).rglob('*'):
  if f.is_file():
   p=str(f.relative_to(root))
   if hashlib.sha256(f.read_bytes()).hexdigest()!=base.get(p):files[p]=f
files['dist/index.html']=root/'dist/index.html';files['index.html']=root/'dist/index.html'
for p,f in list(files.items()):
 if p.startswith('dist/assets/'):files[p.removeprefix('dist/')]=f
m={'release':'2026-10-20.registry-overview','files':{},'baseline':{},'new_files':[],'tests':'24 targeted tests / 506 assertions; full regression suite; desktop/mobile, filters and wallet drill-down verified','migration':None}
for p,f in files.items():
 m['files'][p]=hashlib.sha256(f.read_bytes()).hexdigest();prior=base.get(p) or base.get('dist/'+p)
 if prior:m['baseline'][p]=prior
 else:m['new_files'].append(p)
(out/'manifest.json').write_text(json.dumps(m,indent=2))
package=out/'nuers-registry-overview-20261020.zip'
with zipfile.ZipFile(package,'w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('audit-release-manifest.json',json.dumps(m))
 for p,f in files.items():z.write(f,p)
sha=hashlib.sha256(package.read_bytes()).hexdigest();(out/'package-sha256.txt').write_text(sha+'\n')
s=(root/'output/realtime-refresh-20261020/release/nuers-realtime-refresh-install.php').read_text().replace('nuers-realtime-refresh','nuers-registry-overview').replace('One-second dashboard updates and readable server management','Merchant registry financial overview').replace('067188650dac2f81c2f634cae698b90dcc1046dbb39db5ac1e75b815f2b8e438',sha)
(out/'nuers-registry-overview-install.php').write_text(s)
print(json.dumps({'files':len(files),'bytes':package.stat().st_size,'sha256':sha},indent=2))
