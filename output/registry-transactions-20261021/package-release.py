from pathlib import Path
import hashlib,json,zipfile,re
r=Path.cwd();o=r/'output/registry-transactions-20261021';d=o/'release';d.mkdir(exist_ok=True)
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
old=json.loads((o/'baseline.json').read_text());old.update({n[5:]:h for n,h in list(old.items()) if n.startswith('dist/assets/')});old['index.html']=old['dist/index.html']
paths=json.loads((o/'changed-sources.json').read_text())
files={p:r/p for p in paths}
for folder in ['public/build','dist']:
 for p in (r/folder).rglob('*'):
  if p.is_file() and old.get(str(p.relative_to(r)))!=sha(p): files[str(p.relative_to(r))]=p
for n,p in list(files.items()):
 if n.startswith('dist/assets/'): files[n[5:]]=p
 if n=='dist/index.html': files['index.html']=p
baseline={p:old[p] for p in files if p in old}
manifest={'release':'2026-10-21.registry-transactions.1','purpose':'Business Details merchant-scoped transaction workspace, filters, evidence and branded PDF/CSV reports','files':{n:sha(p) for n,p in files.items()},'baseline':baseline,'new_files':[p for p in files if p not in baseline],'schema_migration':False}
(d/'manifest.json').write_text(json.dumps(manifest,indent=2))
archive=d/'nuers-registry-transactions-20261021.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('audit-release-manifest.json',json.dumps(manifest))
 for n,p in files.items(): z.write(p,n)
s=(r/'output/bir-transmission-center-20261020/release/nuers-bir-center-install.php').read_text().replace('nuers-bir-center','nuers-registry-transactions').replace('20261020','20261021').replace('Company BIR transmission preparation workspace','Business Details scoped transaction activity')
s=re.sub(r"\$sha='[a-f0-9]{64}'", "$sha='"+sha(archive)+"'", s)
s='\n'.join(line for line in s.split('\n') if "'migrate','--path=" not in line)
s=s.replace('Additive batch tables are retained on rollback to preserve audit evidence; no financial data is deleted.','No schema changes are included; no financial data is deleted.')
(d/'nuers-registry-transactions-install.php').write_text(s)
print(json.dumps({'files':len(files),'sha256':sha(archive),'bytes':archive.stat().st_size}))
