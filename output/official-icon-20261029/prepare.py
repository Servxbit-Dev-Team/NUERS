from pathlib import Path
import hashlib,json,shutil,subprocess,struct
root=Path.cwd(); out=root/'output/official-icon-20261029'; source=Path('/Users/marksioson/Downloads/Glossy Blue N Logo with Pixel Tiles.png')
files=['index.html','resources/views/app.blade.php','resources/views/errors/429.blade.php','src/components/enterprise/enterprise-shell.tsx','vite.static.config.ts','public/icon.png','public/nuers-favicon.png','public/favicon.ico','public/apple-touch-icon.png','public/app-icon-192.png','public/app-icon-512.png','public/site.webmanifest']
tracked={p:hashlib.sha256((root/p).read_bytes()).hexdigest() for p in files if (root/p).is_file()}
for base in ['dist','public/build']:
 for p in (root/base).rglob('*'):
  if p.is_file(): tracked[str(p.relative_to(root))]=hashlib.sha256(p.read_bytes()).hexdigest()
(out/'baseline.json').write_text(json.dumps(tracked,indent=2))
for p in files:
 if (root/p).is_file():
  dest=out/'before'/p;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(root/p,dest)
version=hashlib.sha256(source.read_bytes()).hexdigest()[:12]
shutil.copy2(source,root/'public/icon.png')
for name,size in [('nuers-favicon.png',48),('apple-touch-icon.png',180),('app-icon-192.png',192),('app-icon-512.png',512)]:
 subprocess.run(['sips','-z',str(size),str(size),str(source),'--out',str(root/'public'/name)],check=True,capture_output=True)
# Package original-artwork PNG renditions in a standard multi-resolution ICO container.
entries=[]
for size in [16,32,48,256]:
 p=out/f'icon-{size}.png';subprocess.run(['sips','-z',str(size),str(size),str(source),'--out',str(p)],check=True,capture_output=True);entries.append((size,p.read_bytes()))
header=struct.pack('<HHH',0,1,len(entries)); directory=b'';payload=b'';offset=6+16*len(entries)
for size,data in entries:
 directory+=struct.pack('<BBBBHHII',size if size<256 else 0,size if size<256 else 0,0,0,1,32,len(data),offset);payload+=data;offset+=len(data)
(root/'public/favicon.ico').write_bytes(header+directory+payload)
manifest={'id':'/','name':'NUERS — National Unified Electronic Receipt System','short_name':'NUERS','start_url':'/','scope':'/','display':'browser','background_color':'#ffffff','theme_color':'#062344','icons':[{'src':f'/app-icon-{size}.png?v={version}','sizes':f'{size}x{size}','type':'image/png','purpose':'any'} for size in [192,512]]}
(root/'public/site.webmanifest').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n')
head=f'''<link rel="icon" type="image/x-icon" href="/favicon.ico?v={version}" />
    <link rel="icon" type="image/png" sizes="48x48" href="/nuers-favicon.png?v={version}" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v={version}" />
    <link rel="manifest" href="/site.webmanifest?v={version}" />
    <meta name="theme-color" content="#062344" />'''
for name in ['index.html','resources/views/app.blade.php']:
 p=root/name;s=p.read_text();assert s.count('<link rel="icon" type="image/png" href="/nuers-favicon.png" />')==1;s=s.replace('<link rel="icon" type="image/png" href="/nuers-favicon.png" />',head);p.write_text(s)
p=root/'resources/views/errors/429.blade.php';s=p.read_text();s=s.replace('<link rel="icon" href="{{ $prefix }}/nuers-favicon.png">',head.replace('href="/', 'href="{{ $prefix }}/'));p.write_text(s)
p=root/'src/components/enterprise/enterprise-shell.tsx';s=p.read_text().replace('src="/nuers-favicon.png"',f'src="/app-icon-192.png?v={version}"');p.write_text(s)
p=root/'vite.static.config.ts';s=p.read_text().replace('  "nuers-favicon.png",','  "nuers-favicon.png",\n  "favicon.ico",\n  "apple-touch-icon.png",\n  "app-icon-192.png",\n  "app-icon-512.png",\n  "site.webmanifest",');p.write_text(s)
(out/'source.json').write_text(json.dumps({'source':str(source),'sha256':hashlib.sha256(source.read_bytes()).hexdigest(),'version':version,'files':files},indent=2))
print(json.dumps({'version':version,'assets':[(p.name,p.stat().st_size) for p in (root/'public').glob('*') if p.name in ['icon.png','nuers-favicon.png','favicon.ico','apple-touch-icon.png','app-icon-192.png','app-icon-512.png','site.webmanifest']]}))
