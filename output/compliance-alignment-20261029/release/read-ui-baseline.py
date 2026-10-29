from pathlib import Path
import json
out=Path('output/compliance-alignment-20261029/release');paths=[]
for base in ['dist/assets','public/build']:
 for p in Path(base).rglob('*'):
  if p.is_file():
   paths.append(str(p))
   if base=='dist/assets':paths.append('assets/'+str(p.relative_to(base)))
paths+=['dist/index.html','index.html','src/pages/developers/docs.tsx','src/pages/merchant/api-keys.tsx']
for name in ['NUERS-API-Connection-Guide.pdf','NUERS-API-Developer-Documentation.pdf']:
 for prefix in ['guides','dist/guides','public/guides']:paths.append(prefix+'/'+name)
script="<?php $root='/var/www/vhosts/nuers.net/httpdocs';$paths=json_decode('"+json.dumps(paths)+"',true);$h=[];foreach($paths as$p)$h[$p]=is_file($root.'/'.$p)?hash_file('sha256',$root.'/'.$p):null;echo json_encode(['files'=>$h]);"
(out/'read-ui-baseline.php').write_text(script)
