from pathlib import Path
import hashlib,json,zipfile
root=Path.cwd(); out=root/'output/query-scale-audit-20261020/release'
base=json.loads((out.parent/'before-hashes.json').read_text())
files={}
for folder in ['app','routes','database/migrations','src','public/build','dist/assets']:
 for f in (root/folder).rglob('*'):
  if f.is_file():
   p=str(f.relative_to(root))
   if hashlib.sha256(f.read_bytes()).hexdigest()!=base.get(p): files[p]=f
files['dist/index.html']=root/'dist/index.html';files['index.html']=root/'dist/index.html'
for p,f in list(files.items()):
 if p.startswith('dist/assets/'): files[p.removeprefix('dist/')]=f
m={'release':'2026-10-20.query-scale','files':{},'baseline':{},'new_files':[],'tests':'610 full regression tests / 6639 assertions; 69 final focused tests / 708 assertions; isolated million-record query and export verification','migration':'database/migrations/2026_10_20_000003_create_ledger_read_model.php'}
first=json.loads((out/'first-attempt-manifest.json').read_text())
m['previous_attempt']={p:first['files'][p] for p in first['new_files']}
for p,f in files.items():
 m['files'][p]=hashlib.sha256(f.read_bytes()).hexdigest()
 prior=base.get(p) or base.get('dist/'+p)
 if prior:m['baseline'][p]=prior
 else:m['new_files'].append(p)
(out/'manifest.json').write_text(json.dumps(m,indent=2))
package=out/'nuers-query-scale-20261020.zip'
with zipfile.ZipFile(package,'w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('audit-release-manifest.json',json.dumps(m))
 for p,f in files.items():z.write(f,p)
sha=hashlib.sha256(package.read_bytes()).hexdigest();(out/'package-sha256.txt').write_text(sha+'\n')
s=(root/'output/merchant-loading-20261020/release/nuers-merchant-loading-install.php').read_text().replace('nuers-merchant-loading','nuers-query-scale').replace('Merchant dashboard loading fix','Indexed reporting capacity update').replace('167b0d3accf635d018d907f581341277294915c8a90cf3caa3092b3fc7eed4c0',sha).replace('set_time_limit(180)','set_time_limit(300)').replace('$p->setTimeout(90)','$p->setTimeout(180)').replace("'schema_migration'=>false","'schema_migration'=>true")
s=s.replace("$r[$t]=hash('sha256',json_encode(DB::table($t)->orderBy($key)->get()));", "{$h=hash_init('sha256');foreach(DB::table($t)->orderBy($key)->lazyById(200,$key) as $row)hash_update($h,json_encode($row).\"\\n\");$r[$t]=hash_final($h);}")
needle=" $run([$php,$root.'/artisan','config:clear'],'config-clear.log',$backup);"
bootstrap="require 'vendor/autoload.php'; $a=require 'bootstrap/app.php'; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); "
repair=bootstrap+"$p=app(App\\Services\\LedgerProjection::class); $p->installTriggers();"
s=s.replace(needle,needle+"\n $run([$php,$root.'/artisan','migrate','--path="+m['migration']+"','--force'],'migration.log',$backup);\n $run([$php,'-r',"+json.dumps(repair)+"],'projection-triggers.log',$backup);\n $run([$php,$root.'/artisan','reports:refresh','--rebuild','--activate','--max-seconds=120'],'projection-backfill.log',$backup);")
# JSON string is PHP double-quoted here: escape dollar signs so PHP does not interpolate the child command.
s=s.replace(json.dumps(repair),"'"+repair.replace("'","\\'")+"'")
rollback=bootstrap+"if(Illuminate\\Support\\Facades\\Schema::hasTable('ledger_projection_state')){Illuminate\\Support\\Facades\\DB::table('ledger_projection_state')->where('id',1)->update(['ready'=>false]);}"
s=s.replace('}catch(Throwable$e){','}catch(Throwable$e){\n try{$run([$php,\'-r\',\''+rollback.replace("'","\\'")+"'],'projection-disabled.log',$backup);}catch(Throwable){}")
s=s.replace('// No schema or business-data mutation. Previous route and asset manifests are restored.','// Additive read-model tables/indexes/queue triggers remain for retry; indexed reads are disabled. Original financial records are never rolled back.')
s=s.replace("hash_file('sha256',$root.'/'.$p)!==$hash", "!in_array(hash_file('sha256',$root.'/'.$p),[$hash,$manifest['previous_attempt'][$p]??null],true)")
(out/'nuers-query-scale-install.php').write_text(s)
print(json.dumps({'files':len(files),'bytes':package.stat().st_size,'sha256':sha}))
