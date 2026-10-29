from pathlib import Path
import hashlib,json,shutil,subprocess
root=Path.cwd();out=root/'output/official-logo-20261029';source=Path('/Users/marksioson/Downloads/Glossy NUERS Philippine Digital Logo.png')
aliases=['NUERSLOGO.png','NUERSLOGOWHITE.png','NUERS copy.png','NUERS.png','NUERS_WHITE.png','auth-assets/nuers-digital-logo.png','auth-assets/nuers-digital-white.png','nuers_wBG.jpg']
oldrefs=['/auth-assets/nuers-digital-logo.png','/auth-assets/nuers-digital-white.png','/NUERSLOGO.png','/NUERSLOGOWHITE.png','/nuers_wBG.jpg']
paths=set(['index.html','resources/views/app.blade.php','vite.static.config.ts','scripts/document-renderer.tsx','bootstrap/ssr/document-renderer.mjs'])
for base in ['src','resources/views','app']:
 for p in (root/base).rglob('*'):
  if p.suffix not in ['.tsx','.ts','.php','.html']:continue
  if any(s in p.read_text() for s in oldrefs+['auth-assets/nuers-digital-logo.png']):paths.add(str(p.relative_to(root)))
paths.update('public/'+a for a in aliases)
paths.update(str(p.relative_to(root)) for p in (root/'public/guides').glob('*') if p.suffix in ['.pdf','.zip'])
# Preserve pre-change copies for rollback, plus checksums of generated bundles.
baseline={}
for name in sorted(paths):
 p=root/name
 if p.exists():
  baseline[name]=hashlib.sha256(p.read_bytes()).hexdigest();dest=out/'before'/name;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(p,dest)
for base in ['dist','public/build']:
 for p in (root/base).rglob('*'):
  if p.is_file():baseline[str(p.relative_to(root))]=hashlib.sha256(p.read_bytes()).hexdigest()
(out/'baseline.json').write_text(json.dumps(baseline,indent=2))
brand=root/'public/brand';brand.mkdir(exist_ok=True);shutil.copy2(source,brand/'nuers-official-logo-original.png')
subprocess.run(['sips','-Z','1000',str(source),'--out',str(brand/'nuers-official-logo.png')],check=True,capture_output=True)
for alias in aliases:
 dest=root/'public'/alias
 if dest.suffix=='.jpg':subprocess.run(['sips','-s','format','jpeg',str(brand/'nuers-official-logo.png'),'--out',str(dest)],check=True,capture_output=True)
 else:shutil.copy2(brand/'nuers-official-logo.png',dest)
for name in sorted(paths):
 if not name.endswith(('.tsx','.ts','.php','.html')) or name=='vite.static.config.ts':continue
 p=root/name;s=p.read_text()
 for ref in oldrefs:s=s.replace(ref,'/brand/nuers-official-logo.png')
 s=s.replace("public_path('auth-assets/nuers-digital-logo.png')","public_path('brand/nuers-official-logo.png')")
 if name=='src/components/layouts/auth-shell.tsx':s=s.replace('src="/brand/nuers-official-logo.png"\n            alt=""','src="/auth-assets/nuers-login-background.png"\n            alt=""')
 if name=='src/components/layouts/dashboard-layout.tsx':s=s.replace('src="/brand/nuers-official-logo.png"\n                alt="NUERS"\n                className="hidden h-8','src="/app-icon-192.png?v=b50631c79afb"\n                alt="NUERS"\n                className="hidden h-8')
 p.write_text(s)
p=root/'vite.static.config.ts';p.write_text(p.read_text().replace('  "auth-assets",','  "brand",\n  "auth-assets",'))
meta={'source':str(source),'source_sha256':hashlib.sha256(source.read_bytes()).hexdigest(),'assets':['public/brand/nuers-official-logo.png','public/brand/nuers-official-logo-original.png']+['public/'+x for x in aliases],'files':sorted(paths)};(out/'source.json').write_text(json.dumps(meta,indent=2));print(json.dumps({'changed_sources':len(paths),'logo_bytes':(brand/'nuers-official-logo.png').stat().st_size}))
