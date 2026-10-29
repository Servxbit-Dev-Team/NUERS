from pathlib import Path
import hashlib,json,zipfile,re,sys
root=Path.cwd();out=root/'output/pti-applications-20261028';dest=out/'release'
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
names=['app/Http/Controllers/Api/PtiApplicationController.php','app/Services/PtiApplications.php','database/migrations/2026_10_28_000002_create_pti_applications.php','resources/views/reports/pti-application.blade.php','src/pages/authorizations/applications.tsx','src/pages/authorizations/applications.css','src/pages/authorizations/index.tsx','src/App.tsx','src/components/layouts/dashboard-layout.tsx','src/components/enterprise/enterprise-shell.tsx','routes/api.php','docs/knowledge/pti-application-workflow.md']
files={n:root/n for n in names}
for p in (root/'public/build').rglob('*'):
 if p.is_file():files[str(p.relative_to(root))]=p
for p in (root/'dist').rglob('*'):
 if not p.is_file():continue
 n=str(p.relative_to(root))
 if n.startswith('dist/assets/'):
  files[n]=p;files[n[5:]]=p
 elif n=='dist/index.html':files[n]=p;files['index.html']=p
if len(sys.argv)>1 and sys.argv[1]=='candidates':
 (out/'candidate-paths.json').write_text(json.dumps(list(files)));print(len(files));sys.exit()
old=json.loads((out/'live-baseline.json').read_text())
original=json.loads((out/'baseline.json').read_text())
for n,h in original.items():
 if n in names and old[n]!=h:raise RuntimeError('Source drift: '+n)
for n in names:
 if n not in original and old[n] is not None and old[n]!=sha(root/n):raise RuntimeError('Unexpected existing module: '+n)
files={n:p for n,p in files.items() if old[n]!=sha(p)}
manifest={'release':'2026-10-28.pti-applications.1','purpose':'Owner-scoped PTI preparation, encrypted evidence, administrative filing tracker and PDF/ZIP packet; no direct BIR submission','files':{n:sha(p) for n,p in files.items()},'baseline':{n:old[n] for n in files if old[n] is not None},'new_files':[n for n in files if old[n] is None],'schema_migration':True}
(dest/'manifest.json').write_text(json.dumps(manifest,indent=2))
archive=dest/'nuers-pti-applications-20261028.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('audit-release-manifest.json',json.dumps(manifest))
 for n,p in files.items():z.write(p,n)
s=(root/'output/all-pages-performance-20261028/merchant-followup/nuers-merchant-summary-install.php').read_text().replace('nuers-merchant-summary','nuers-pti-applications').replace('Large-register page performance update','PTI application preparation update')
s=re.sub(r"\$sha='[a-f0-9]{64}'", "$sha='"+sha(archive)+"'",s)
s=s.replace('2026_10_28_000001_index_reconciliation_evidence.php','2026_10_28_000002_create_pti_applications.php')
s=s.replace(" $run([$php,$root.'/artisan','reports:refresh','--max-seconds=180'],'sales-index-backfill.log',$backup);\n",'')
s=s.replace('// Additive reporting schema and queue triggers remain on rollback; source financial data is never deleted. Previous routes and manifests are restored.','// Additive PTI tables remain on rollback; no application or source data is deleted. Previous routes and manifests are restored.')
s=s.replace("'merchant_transactions'=>'id'", "'merchant_transactions'=>'id','taxpayer_authorizations'=>'id'")
(dest/'nuers-pti-applications-install.php').write_text(s)
print(json.dumps({'files':len(files),'bytes':archive.stat().st_size,'sha256':sha(archive)}))
