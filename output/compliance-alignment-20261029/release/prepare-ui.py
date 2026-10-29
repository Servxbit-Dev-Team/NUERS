from pathlib import Path
import hashlib,json,zipfile,re
out=Path('output/compliance-alignment-20261029/release');live=json.loads((out/'live-ui-baseline.json').read_text())['files'];paths={}
for p in Path('dist/assets').rglob('*'):
 if p.is_file():
  paths[str(p)]=str(p);paths['assets/'+str(p.relative_to('dist/assets'))]=str(p)
for p in ['dist/index.html','src/pages/developers/docs.tsx','src/pages/merchant/api-keys.tsx']:paths[p]=p
for p in Path('public/build').rglob('*'):
 if p.is_file():paths[str(p)]=str(p)
for name in ['NUERS-API-Connection-Guide.pdf','NUERS-API-Developer-Documentation.pdf']:
 for prefix in ['guides','dist/guides','public/guides']:paths[prefix+'/'+name]='public/guides/'+name
paths['index.html']='dist/index.html'
files={d:s for d,s in paths.items() if live.get(d)!=hashlib.sha256(Path(s).read_bytes()).hexdigest()}
entry=re.search(r'src="(/assets/[^\"]+\.js)"',Path('dist/index.html').read_text()).group(1)
assert 'compliance-operations' in Path('dist'+entry).read_text()
m={'release':'2026-10-29.compliance-operations.ui.1','entry':entry,'files':{d:hashlib.sha256(Path(s).read_bytes()).hexdigest() for d,s in files.items()},'baseline':{d:live.get(d) for d in files}}
(out/'ui-manifest.json').write_text(json.dumps(m,indent=2))
zpath=out/'nuers-compliance-ui-20261029.zip'
with zipfile.ZipFile(zpath,'w',zipfile.ZIP_DEFLATED) as z:
 for d,s in files.items():z.write(s,d)
 z.write(out/'ui-manifest.json','ui-manifest.json')
print(json.dumps({'files':len(files),'entry':entry,'bytes':zpath.stat().st_size,'sha256':hashlib.sha256(zpath.read_bytes()).hexdigest()}))
