from pathlib import Path
import hashlib,json,zipfile,re
r=Path.cwd();o=r/'output/bir-transmission-center-20261020';d=o/'release';d.mkdir(exist_ok=True)
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
old=json.loads((o/'baseline.json').read_text());old.update({n[5:]:h for n,h in list(old.items()) if n.startswith('dist/assets/')});old['index.html']=old['dist/index.html']
paths=['app/Services/EisTransmissionCenter.php','app/Services/EisDocumentReadiness.php','app/Services/EisDelivery.php','app/Http/Controllers/Api/EisTransmissionCenterController.php','app/Http/Controllers/Api/EisController.php','routes/api.php','routes/console.php','database/migrations/2026_10_20_000006_eis_preparation_batches.php','src/pages/operations/routes.tsx','src/pages/operations/bir-transmission-center.tsx','src/pages/operations/bir-transmission-center.css','docs/knowledge/bir-transmission-center.md']
files={p:r/p for p in paths}
for folder in ['public/build','dist']:
 for p in (r/folder).rglob('*'):
  if p.is_file() and old.get(str(p.relative_to(r)))!=sha(p): files[str(p.relative_to(r))]=p
for n,p in list(files.items()):
 if n.startswith('dist/assets/'): files[n[5:]]=p
 if n=='dist/index.html': files['index.html']=p
baseline={p:old[p] for p in files if p in old}
manifest={'release':'2026-10-20.bir-transmission-center.1','purpose':'Company-scoped durable BIR document preparation, filters and transmission evidence','files':{n:sha(p) for n,p in files.items()},'baseline':baseline,'new_files':[p for p in files if p not in baseline],'schema_migration':'2026_10_20_000006_eis_preparation_batches'}
(d/'manifest.json').write_text(json.dumps(manifest,indent=2))
archive=d/'nuers-bir-center-20261020.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('audit-release-manifest.json',json.dumps(manifest))
 for n,p in files.items(): z.write(p,n)
s=(r/'output/submission-failures-20261020/release/nuers-submission-outcomes-install.php').read_text().replace('nuers-submission-outcomes','nuers-bir-center').replace('2026_10_20_000005_submission_outcomes','2026_10_20_000006_eis_preparation_batches').replace('Submission outcome visibility and HTTP 429 branding','Company BIR transmission preparation workspace')
s=re.sub(r"\$sha='[a-f0-9]{64}'", "$sha='"+sha(archive)+"'", s)
s=s.replace('Additive nullable log columns and indexes are retained on rollback; no historical log or financial data is deleted.','Additive batch tables are retained on rollback to preserve audit evidence; no financial data is deleted.')
(d/'nuers-bir-center-install.php').write_text(s)
print(json.dumps({'files':len(files),'sha256':sha(archive),'bytes':archive.stat().st_size,'paths':list(files)}))
