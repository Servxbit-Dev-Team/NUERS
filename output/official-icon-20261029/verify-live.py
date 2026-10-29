from pathlib import Path
import urllib.request,json,hashlib
out=Path('output/official-icon-20261029');version=json.loads((out/'source.json').read_text())['version'];results=[]
def read(path):
 req=urllib.request.Request('https://nuers.net'+path,headers={'User-Agent':'NUERS-Deployment-Verification'})
 with urllib.request.urlopen(req,timeout=25) as r:return r.status,r.headers.get('Content-Type'),r.read()
for path in ['/','/login','/merchant','/super-admin','/monitoring']:
 status,ctype,data=read(path);body=data.decode();assert f'/nuers-favicon.png?v={version}' in body,path;assert f'/apple-touch-icon.png?v={version}' in body,path;results.append({'path':path,'status':status,'official_icon':True})
for name in ['nuers-favicon.png','favicon.ico','apple-touch-icon.png','app-icon-192.png','app-icon-512.png','site.webmanifest.json','icon.png']:
 status,ctype,data=read('/'+name+'?v='+version);expected=hashlib.sha256((Path('public')/name).read_bytes()).hexdigest();assert hashlib.sha256(data).hexdigest()==expected,name;results.append({'path':'/'+name,'status':status,'content_type':ctype,'sha256':expected})
(out/'http-verification.json').write_text(json.dumps(results,indent=2));print(json.dumps({'verified':True,'checks':len(results),'version':version}))
