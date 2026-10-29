from pathlib import Path
import hashlib,json,zipfile,re
root=Path.cwd();out=root/'output/all-pages-performance-20261028';dest=out/'release'
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
old=json.loads((out/'baseline-hashes.json').read_text())
# This live hash was read over the authenticated SSH session.
old['docs/knowledge/operations-dashboards.md']='1841583134e3cdde05f5270f682f4e503b2cc7826998ee4488b8c297a21a4847'
files={n:root/n for n in json.loads((out/'changed-sources.json').read_text()) if not n.startswith('tests/')}
for p in (root/'public/build').rglob('*'):
 if p.is_file() and old.get(str(p.relative_to(root)))!=sha(p):files[str(p.relative_to(root))]=p
# The hosted root fallback and its hashed assets are shipped alongside Laravel.
for p in (root/'dist').rglob('*'):
 if p.is_file():
  n=str(p.relative_to(root))
  if not (n.startswith('dist/assets/') or n=='dist/index.html'):continue
  files[n]=p
  if n.startswith('dist/assets/'):files[n[5:]]=p
  if n=='dist/index.html':files['index.html']=p
# Obtain fallback entry baseline from the last successful release manifest.
for mf in ['manifest.json','groups-manifest.json']:
 prev=json.loads((root/'output/sales-summary-performance-20261021/release'/mf).read_text())
 for n,h in prev['files'].items():
  if n.startswith(('dist/','assets/')) or n=='index.html':old[n]=h
files={n:p for n,p in files.items() if old.get(n)!=sha(p)}
manifest={'release':'2026-10-28.page-performance.1','purpose':'Bounded page loads, indexed reconciliation and registers, on-demand route bundles, background full audit verification','files':{n:sha(p) for n,p in files.items()},'baseline':{n:old[n] for n in files if n in old},'new_files':[n for n in files if n not in old],'schema_migration':True}
(dest/'manifest.json').write_text(json.dumps(manifest,indent=2))
archive=dest/'nuers-page-performance-20261028.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('audit-release-manifest.json',json.dumps(manifest))
 for n,p in files.items():z.write(p,n)
s=(root/'output/sales-summary-performance-20261021/release/nuers-sales-performance-install.php').read_text().replace('nuers-sales-performance','nuers-page-performance').replace('20261021','20261028').replace('Indexed sales reporting update','Large-register page performance update')
s=re.sub(r"\$sha='[a-f0-9]{64}'", "$sha='"+sha(archive)+"'",s)
s=s.replace('2026_10_21_000001_index_operations_sales_evidence.php','2026_10_28_000001_index_reconciliation_evidence.php')
s=s.replace('set_time_limit(300)','set_time_limit(600)').replace('$p->setTimeout(200)','$p->setTimeout(300)')
# Migration does not create or replace any database trigger. Retain all source/security settings.
(dest/'nuers-page-performance-install.php').write_text(s)
print(json.dumps({'files':len(files),'bytes':archive.stat().st_size,'sha256':sha(archive)}))
