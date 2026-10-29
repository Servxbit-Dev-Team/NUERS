from pathlib import Path
import hashlib,json,zipfile,re
root=Path.cwd();out=root/'output/all-pages-performance-20261028';dest=out/'merchant-followup';dest.mkdir(exist_ok=True)
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
prev=json.loads((out/'release/manifest.json').read_text())
names=['app/Services/IndexedSalesReport.php','app/Http/Controllers/Api/MerchantRecordsController.php','docs/knowledge/operations-dashboards.md']
m={'release':'2026-10-28.page-performance.2','purpose':'Bounded merchant summary query plans after million-row cold-cache measurements','files':{n:sha(root/n) for n in names},'baseline':{n:prev['files'][n] for n in names},'new_files':[],'schema_migration':False}
(dest/'manifest.json').write_text(json.dumps(m,indent=2))
zp=dest/'nuers-merchant-summary-20261028.zip'
with zipfile.ZipFile(zp,'w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('audit-release-manifest.json',json.dumps(m))
 for n in names:z.write(root/n,n)
s=(out/'release/nuers-page-performance-install.php').read_text().replace('nuers-page-performance','nuers-merchant-summary')
s=re.sub(r"\$sha='[a-f0-9]{64}'", "$sha='"+sha(zp)+"'",s)
s=s.replace(" $run([$php,$root.'/artisan','migrate',", " if($manifest['schema_migration']) $run([$php,$root.'/artisan','migrate',")
(dest/'nuers-merchant-summary-install.php').write_text(s)
v=(out/'release/nuers-page-performance-verify.php').read_text().replace('nuers-page-performance','nuers-merchant-summary')
insert='''
$records=new App\\Http\\Controllers\\Api\\MerchantRecordsController;
foreach(DB::table('merchants')->orderBy('id')->limit(2)->get() as $merchant) {
 foreach(['documents','transactions'] as $mode) {
  $fast=(new ReflectionMethod($records,'summaryBase'))->invoke($records,$merchant,$mode,[],true);
  $detailed=(new ReflectionMethod($records,'base'))->invoke($records,$merchant,$mode);
  $t=microtime(true);$a=$fast?->selectRaw('COUNT(*) as total,COALESCE(SUM(sales_centavos),0) as cents')->first();
  $elapsed=round((microtime(true)-$t)*1000,2);
  $b=$detailed->selectRaw('COUNT(*) as total,COALESCE(SUM(sales_centavos),0) as cents')->first();
  if($a && ((int)$a->total!==(int)$b->total || (int)$a->cents!==(int)$b->cents))throw new RuntimeException('Merchant summary differs from full evidence path');
  $results['merchant_'.substr(hash('sha256',$merchant->id),0,8).'_'.$mode]=['records'=>(int)$b->total,'fast_path'=>$a!==null,'read_ms'=>$elapsed,'totals_match'=>true];
 }
}
'''
v=v.replace('$audit=app(',insert+'\n$audit=app(')
(dest/'nuers-merchant-summary-verify.php').write_text(v)
print(json.dumps({'files':len(names),'sha256':sha(zp),'bytes':zp.stat().st_size}))
