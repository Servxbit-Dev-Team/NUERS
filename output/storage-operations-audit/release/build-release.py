from pathlib import Path
import json, hashlib, zipfile
root=Path.cwd();out=root/'output/storage-operations-audit/release'
base=json.loads((root/'output/storage-operations-audit/before-hashes.json').read_text())
prior=json.loads((out/'manifest.json').read_text())
paths=['app/Services/OperationsHealth.php','app/Services/LocalRecoveryBackup.php','app/Services/RecoveryArchive.php','app/Services/BackupRecovery.php','app/Http/Controllers/Api/OperationalEvidenceController.php','config/operations.php','routes/operations-console.php','routes/console.php','database/migrations/2026_10_20_000002_create_operations_observations.php','src/pages/operations/storage-monitor.tsx','src/pages/operations/readiness.tsx','docs/infrastructure/storage-operations-and-recovery.md','tests/Feature/StorageOperationsTest.php','tests/Support/storage-recovery-lab.php','tests/Support/storage-operations-ui.cjs']
files={p:root/p for p in paths}
for folder in ['public/build','dist/assets']:
 for p in (root/folder).rglob('*'):
  if p.is_file() and hashlib.sha256(p.read_bytes()).hexdigest()!=base.get(str(p.relative_to(root))):files[str(p.relative_to(root))]=p
files['dist/index.html']=root/'dist/index.html';files['index.html']=root/'dist/index.html'
for p in (root/'dist/assets').iterdir():
 if p.is_file() and str(p.relative_to(root)) in files: files['assets/'+p.name]=p
manifest={'release':'2026-10-20.storage-operations','files':{},'baseline':{},'new_files':[],'tests':'548 tests, 5992 assertions; 7 localhost UI checks; isolated encrypted SQLite/object restore'}
for p,f in files.items():
 manifest['files'][p]=hashlib.sha256(f.read_bytes()).hexdigest()
 b='dist/'+p if p=='index.html' or p.startswith('assets/') else p
 if b in base:manifest['baseline'][p]=base[b]
 else:manifest['new_files'].append(p)
for p in list(manifest['new_files']):
 if p in prior['files'] and prior['files'][p] != manifest['files'][p]:
  manifest['baseline'][p]=prior['files'][p];manifest['new_files'].remove(p)
(out/'manifest.json').write_text(json.dumps(manifest,indent=2))
with zipfile.ZipFile(out/'nuers-storage-operations-20261020-v2.zip','w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('audit-release-manifest.json',json.dumps(manifest))
 for p,f in files.items():z.write(f,p)
sha=hashlib.sha256((out/'nuers-storage-operations-20261020-v2.zip').read_bytes()).hexdigest();(out/'package-sha256.txt').write_text(sha+'\n')
s=(root/'output/admin-security-audit/release/nuers-admin-security-audit-install.php').read_text().replace('nuers-admin-security-audit','nuers-storage-operations').replace('nuers-storage-operations-20261020.zip','nuers-storage-operations-20261020-v2.zip').replace('Admin security audit deployment','Storage operations deployment').replace('213a78771d2015c623cc316ac3655ab185f01c6f7524a9d1b2e69fd39d6105f7',sha).replace("'schema_migration'=>false","'schema_migration'=>'operations_observations only'")
s=s.replace(" $verification=json_decode", " $run([$php,$root.'/artisan','migrate','--path=database/migrations/2026_10_20_000002_create_operations_observations.php','--force'],'migration.log',$backup);\n $verification=json_decode")
s=s.replace('// No schema or business-data mutation. Previous route and asset manifests are restored.','// Forward-only observations table is retained on rollback; no merchant data migration.')
(out/'nuers-storage-operations-install-v2.php').write_text(s)
print(json.dumps({'files':len(files),'sha256':sha,'bytes':(out/'nuers-storage-operations-20261020-v2.zip').stat().st_size}))
