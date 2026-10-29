from pathlib import Path
import hashlib,json,zipfile
r=Path.cwd();o=r/'output/submission-failures-20261020';d=o/'release';d.mkdir(exist_ok=True)
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
old=json.loads((o/'baseline.json').read_text())
old.update({n[5:]:h for n,h in list(old.items()) if n.startswith('dist/assets/')})
old['index.html']=old['dist/index.html']
paths=['app/Services/SubmissionAttempts.php','app/Http/Controllers/Api/SubmissionAttemptsController.php','app/Http/Controllers/Api/IntegrationTransactionController.php','app/Http/Controllers/Api/EnterpriseDashboardController.php','app/Http/Middleware/IntegrationApiResponse.php','bootstrap/app.php','routes/api.php','database/migrations/2026_10_20_000005_submission_outcomes.php','resources/views/errors/429.blade.php','src/components/admin/submission-attempts.tsx','src/components/admin/submission-attempts.css','src/components/enterprise/enterprise-dashboard.tsx','public/error-assets/retry-countdown.js','docs/knowledge/failed-submissions-and-rate-limits.md']
files={p:r/p for p in paths}
for folder in ['public/build','dist']:
 for p in (r/folder).rglob('*'):
  if p.is_file() and old.get(str(p.relative_to(r)))!=sha(p):files[str(p.relative_to(r))]=p
for n,p in list(files.items()):
 if n.startswith('dist/assets/'):files[n[5:]]=p
 if n=='dist/index.html':files['index.html']=p
 if n=='dist/error-assets/retry-countdown.js':files['error-assets/retry-countdown.js']=p
baseline={p:old[p] for p in files if p in old}
manifest={'release':'2026-10-20.submission-outcomes.1','purpose':'Failed merchant submission evidence and branded HTTP 429 page','files':{n:sha(p) for n,p in files.items()},'baseline':baseline,'new_files':[p for p in files if p not in baseline],'schema_migration':'2026_10_20_000005_submission_outcomes'}
(d/'manifest.json').write_text(json.dumps(manifest,indent=2))
archive=d/'nuers-submission-outcomes-20261020.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('audit-release-manifest.json',json.dumps(manifest))
 for n,p in files.items():z.write(p,n)
s=(r/'output/transaction-report-20261020/queue-release/nuers-transaction-pdf-worker-install.php').read_text().replace('nuers-transaction-pdf-worker','nuers-submission-outcomes').replace('618fc4598f25ef97cc8e82f25d58621c4f6356dead14092e2b85c5037af95244',sha(archive)).replace('Private transaction PDF worker','Submission outcome visibility and HTTP 429 branding')
a=s.index("if($mode==='check')");b=s.index("$resultPath=",a)
s=s[:a]+"if($mode==='check'){echo json_encode(['preflight'=>true,'target'=>'nuers.net','files'=>count($manifest['files']),'php'=>PHP_VERSION,'schema_migration'=>$manifest['schema_migration'],'controller_disabled'=>true,'source_baseline_matches'=>true]).PHP_EOL;exit;}\n"+s[b:]
s=s.replace(" $run([$php,$root.'/artisan','config:clear']", " $run([$php,$root.'/artisan','migrate','--path=database/migrations/2026_10_20_000005_submission_outcomes.php','--force'],'migration.log',$backup);\n $run([$php,$root.'/artisan','config:clear']")
s=s.replace('// No schema or business-data mutation. Previous route and asset manifests are restored.', '// Additive nullable log columns and indexes are retained on rollback; no historical log or financial data is deleted. Previous routes and manifests are restored.')
(d/'nuers-submission-outcomes-install.php').write_text(s)
print(json.dumps({'files':len(files),'sha256':sha(archive),'bytes':archive.stat().st_size}))
