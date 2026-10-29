from pathlib import Path
import json,hashlib,zipfile
r=Path.cwd();o=r/'output/submission-failures-20261020';d=o/'time-release';d.mkdir(exist_ok=True)
old=json.loads((o/'release/manifest.json').read_text());paths=['app/Http/Controllers/Api/SubmissionAttemptsController.php','app/Services/SubmissionAttempts.php'];sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
m={'release':'2026-10-20.submission-outcomes.2','files':{p:sha(r/p) for p in paths},'baseline':{p:old['files'][p] for p in paths},'new_files':[],'schema_migration':False}
(d/'manifest.json').write_text(json.dumps(m,indent=2));z=d/'nuers-submission-time-20261020.zip'
with zipfile.ZipFile(z,'w',zipfile.ZIP_DEFLATED) as a:
 a.writestr('audit-release-manifest.json',json.dumps(m))
 for p in paths:a.write(r/p,p)
s=(o/'release/nuers-submission-outcomes-install.php').read_text().replace('nuers-submission-outcomes','nuers-submission-time').replace('063ee2ba3348e98a1299b6b9781ab7b7d2036659f9e1247e34bed4cd1066ee3e',sha(z))
s=s.replace(" $run([$php,$root.'/artisan','migrate','--path=database/migrations/2026_10_20_000005_submission_outcomes.php','--force'],'migration.log',$backup);\n",'')
(d/'nuers-submission-time-install.php').write_text(s)
s=(o/'release/nuers-submission-outcomes-verify.php').read_text().replace("'outcome_schema'=>true", "'outcome_schema'=>true,'timestamp_timezone'=>config('app.timezone')")
(d/'nuers-submission-time-verify.php').write_text(s)
print(json.dumps({'files':len(paths),'sha256':sha(z),'bytes':z.stat().st_size}))
