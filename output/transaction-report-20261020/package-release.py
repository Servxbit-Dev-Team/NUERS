from pathlib import Path
import hashlib,json,zipfile
root=Path.cwd();out=root/'output/transaction-report-20261020';release=out/'release';release.mkdir(exist_ok=True)
before=json.loads((out/'baseline.json').read_text());sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
files={}
for d in ['app','src','resources','routes','docs','public/build','dist','bootstrap/ssr']:
 for p in (root/d).rglob('*'):
  if p.is_file() and before.get(str(p.relative_to(root)))!=sha(p):files[str(p.relative_to(root))]=p
for name,p in list(files.items()):
 if name.startswith('dist/assets/'):files[name[5:]]=p
 if name=='dist/index.html':files['index.html']=p
baseline={}
for name,p in files.items():
 old=before.get('dist/'+name if name.startswith('assets/') or name=='index.html' else name)
 if old:baseline[name]=old
manifest={'release':'2026-10-20.transaction-reports.1','purpose':'Admin transaction filters, detailed register and branded PDF/CSV exports','files':{name:sha(p) for name,p in files.items()},'baseline':baseline,'new_files':[name for name in files if name not in baseline],'schema_migration':False}
(release/'manifest.json').write_text(json.dumps(manifest,indent=2))
archive=release/'nuers-transaction-reports-20261020.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('audit-release-manifest.json',json.dumps(manifest))
 for name,p in files.items():z.write(p,name)
old=(root/'output/registry-actions-20261020/release/nuers-registry-actions-install-v2.php').read_text()
old=old.replace('nuers-registry-actions','nuers-transaction-reports').replace('Merchant registry actions interface','Transaction register and PDF exports').replace('a4efbbdad41bf666efb3aa66586d422e73320004c07e6066152709a71459ce61',sha(archive))
# Test the already installed document runtime before changing the site.
old=old.replace("if($mode==='check'){echo", "if($mode==='check'){$probe=app(App\\Services\\DocumentPdf::class)->renderHtml('<!doctype html><html><head><meta charset=\"utf-8\"></head><body>NUERS private PDF runtime check</body></html>');if(!str_starts_with($probe,'%PDF-'))throw new RuntimeException('PDF runtime preflight failed');unset($probe);echo")
(release/'nuers-transaction-reports-install.php').write_text(old)
print(json.dumps({'files':len(files),'archive':str(archive),'sha256':sha(archive),'size':archive.stat().st_size,'paths':list(files)},indent=2))
