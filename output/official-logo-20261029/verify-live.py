from pathlib import Path
import urllib.request,json,hashlib
out=Path('output/official-logo-20261029');meta=json.loads((out/'source.json').read_text());version=meta['version'];results=[]
def read(path):
 print('Checking '+path,flush=True)
 request=urllib.request.Request('https://nuers.net'+urllib.parse.quote(path,safe='/?=&'),headers={'User-Agent':'NUERS-Deployment-Verification'})
 with urllib.request.urlopen(request,timeout=40) as r:return r.status,r.headers.get('Content-Type'),r.read()
for path in ['/','/login','/merchant','/super-admin','/monitoring','/api/docs']:
 status,ctype,data=read(path);body=data.decode();assert '/brand/nuers-official-logo.png' in body,path;assert '/nuers-favicon.png?v=b50631c79afb' in body,path;results.append({'path':path,'status':status,'official_brand':True,'official_icon_retained':True})
for source in meta['assets']:
 name=source.removeprefix('public/');status,ctype,data=read('/'+name+'?v='+version);h=hashlib.sha256(data).hexdigest();assert h==hashlib.sha256(Path(source).read_bytes()).hexdigest(),name;results.append({'path':'/'+name,'status':status,'content_type':ctype,'sha256':h})
for name in ['NUERS-API-Connection-Guide.pdf','NUERS-API-Developer-Documentation.pdf','NUERS-Principal-and-Supplementary-Documents-v1.0.0.pdf','NUERS-Document-Sample-Copies-v1.0.0.zip']:
 status,ctype,data=read('/guides/'+name+'?v='+version);h=hashlib.sha256(data).hexdigest();assert h==hashlib.sha256((Path('public/guides')/name).read_bytes()).hexdigest(),name;results.append({'path':'/guides/'+name,'status':status,'content_type':ctype,'sha256':h})
(out/'http-verification.json').write_text(json.dumps(results,indent=2));print(json.dumps({'verified':True,'checks':len(results),'version':version}))
