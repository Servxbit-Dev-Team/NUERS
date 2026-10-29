from pathlib import Path
import json,hashlib,zipfile,re
r=Path.cwd();o=r/'output/sales-summary-performance-20261021';d=o/'release'
h=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
previous=json.loads((d/'manifest.json').read_text());p='app/Services/OperationsSalesQuery.php'
m={'release':'2026-10-21.sales-performance.2','purpose':'MariaDB strict-mode grouped sales report compatibility','files':{p:h(r/p)},'baseline':{p:previous['files'][p]},'new_files':[],'schema_migration':False}
a=d/'nuers-sales-groups-20261021.zip'
with zipfile.ZipFile(a,'w',zipfile.ZIP_DEFLATED) as z:z.writestr('audit-release-manifest.json',json.dumps(m));z.write(r/p,p)
(d/'groups-manifest.json').write_text(json.dumps(m,indent=2))
s=(r/'output/registry-transactions-20261021/release/nuers-registry-transactions-install.php').read_text().replace('nuers-registry-transactions','nuers-sales-groups').replace('Business Details scoped transaction activity','MariaDB grouped sales compatibility')
s=re.sub(r"\$sha='[a-f0-9]{64}'", "$sha='"+h(a)+"'",s)
(d/'nuers-sales-groups-install.php').write_text(s)
s=(d/'nuers-sales-performance-verify.php').read_text().replace('nuers-sales-performance-stage','nuers-sales-groups-stage')
s=s.replace("echo json_encode(['verified'", """$groups=[];foreach(['daily','monthly','business','type','payment','branch']as$g){$rr=Illuminate\\Http\\Request::create('/','GET',['mode'=>'sales','per_page'=>10,'group'=>$g]);$tt=microtime(true);$bb=$probe->read($rr)->getData(true);if($bb['total']!==$body['total'])throw new RuntimeException('Grouped total mismatch');$groups[$g]=['groups'=>count($bb['groups']),'ms'=>round((microtime(true)-$tt)*1000,2)];}
echo json_encode(['group_verification'=>$groups,'verified'""")
(d/'nuers-sales-groups-verify.php').write_text(s)
print(json.dumps({'files':len(m['files']),'sha256':h(a)}))
