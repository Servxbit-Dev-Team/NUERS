#!/usr/bin/env python3
"""Assemble only the verified base runtime, scoped remediation and reconstructed frontend."""
import hashlib,json,tarfile,io
from pathlib import Path
root=Path(__file__).resolve().parents[2]
base=root/'.local/aws-live-sync-staging-2026-11-14'
out=root/'.local/linux-staging-2026-11-14';out.mkdir(parents=True,exist_ok=True)
manifest=json.loads((root/'deployment/reports/NUERS_Remediation_Manifest_2026-11-14.json').read_text())
frontend=json.loads((root/'deployment/reports/NUERS_Frontend_Reconstruction_Provenance_2026-11-14.json').read_text())
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
files={}
for d in ['app','config','routes','database/migrations','resources/views','resources/data']:
 for p in (base/d).rglob('*'):
  if p.is_file() and not p.is_symlink():files[p.relative_to(base).as_posix()]=p
for n in ['artisan','composer.json','composer.lock','bootstrap/app.php','bootstrap/providers.php','public/index.php','public/.htaccess']:
 p=base/n
 if p.is_file() and not p.is_symlink():files[n]=p
for f in manifest['files']:
 if f['kind'] not in ['runtime','test-support']:continue
 n=f['path'];p=root/n
 assert sha(p)==f['candidate_sha256'],n
 if f['kind']=='runtime' and f['base_sha256'] is not None: assert sha(base/n)==f['base_sha256'],f'Base changed: {n}'
 files[n]=p
for n,h in {**frontend['source_files'],**frontend['generated_files']}.items():
 assert sha(root/n)==h,n
 files[n]=root/n
# Include reviewed synthetic factory/seeder and example dependencies needed by recovered tests.
for n in ['database/factories/UserFactory.php','database/seeders/DatabaseSeeder.php','docs/examples/nuers-webhook-receiver.php']:
 files[n]=root/n
for d in ['tests','deployment/staging']:
 for p in (root/d).rglob('*'):
  if p.is_file() and not p.is_symlink() and '__pycache__' not in p.parts:files[p.relative_to(root).as_posix()]=p
files['phpunit.xml']=root/'phpunit.xml'
for n in files:
 assert not any(s in n.split('/') for s in ['.env','node_modules','vendor','.git','.local','uploads','storage']),n
# The SSR renderer requires these public assets at runtime; hash inventory alone
# cannot catch an asset accidentally omitted from both the archive and inventory.
for n in ['public/brand/nuers-official-logo.png','public/invoice-assets/receipt-reference.png','src/components/invoices/electronic-document.css']:
 assert n in files, f'Required document runtime asset missing: {n}'
inventory={n:sha(p) for n,p in sorted(files.items())}
archive=out/'candidate.tar.gz'
with tarfile.open(archive,'w:gz') as t:
 for n,p in sorted(files.items()):t.add(p,arcname=n,recursive=False)
 data=json.dumps({'base_release':manifest['base_release'],'files':inventory},indent=2).encode()
 info=tarfile.TarInfo('staging-file-inventory.json');info.size=len(data);info.mode=0o644;t.addfile(info,io.BytesIO(data))
with tarfile.open(archive) as t:
 for m in t.getmembers():
  if m.name in inventory:assert hashlib.sha256(t.extractfile(m).read()).hexdigest()==inventory[m.name]
report={'base_release':manifest['base_release'],'archive':str(archive.relative_to(root)),'sha256':sha(archive),'file_count':len(files),'bytes':archive.stat().st_size,'excludes':['production env/secrets','customer database/uploads','installed dependencies','unscoped local backend edits'],'files':inventory}
(out/'candidate-inventory.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps({k:v for k,v in report.items() if k!='files'},indent=2))
