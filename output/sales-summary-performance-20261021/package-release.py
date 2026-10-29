from pathlib import Path
import hashlib,json,zipfile,re
r=Path.cwd();o=r/'output/sales-summary-performance-20261021';d=o/'release';d.mkdir(exist_ok=True)
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
old=json.loads((o/'baseline.json').read_text());old.update({n[5:]:h for n,h in list(old.items()) if n.startswith('dist/assets/')});old['index.html']=old['dist/index.html']
# Live preflight confirmed this internal guide has not previously been deployed.
old['docs/knowledge/operations-dashboards.md']=None
paths=json.loads((o/'changed-sources.json').read_text());files={p:r/p for p in paths}
for folder in ['public/build','dist']:
 for p in (r/folder).rglob('*'):
  if p.is_file() and old.get(str(p.relative_to(r)))!=sha(p):files[str(p.relative_to(r))]=p
for n,p in list(files.items()):
 if n.startswith('dist/assets/'):files[n[5:]]=p
 if n=='dist/index.html':files['index.html']=p
baseline={p:old[p] for p in files if p in old}
manifest={'release':'2026-10-21.sales-performance.1','purpose':'Bounded Summary List of Sales, validated reporting evidence, indexed SQL totals, pagination and retry UI','files':{n:sha(p) for n,p in files.items()},'baseline':baseline,'new_files':[p for p in files if p not in baseline],'schema_migration':True}
(d/'manifest.json').write_text(json.dumps(manifest,indent=2));archive=d/'nuers-sales-performance-20261021.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('audit-release-manifest.json',json.dumps(manifest))
 for n,p in files.items():z.write(p,n)
s=(r/'output/registry-transactions-20261021/release/nuers-registry-transactions-install.php').read_text().replace('nuers-registry-transactions','nuers-sales-performance').replace('Business Details scoped transaction activity','Indexed sales reporting update')
s=re.sub(r"\$sha='[a-f0-9]{64}'", "$sha='"+sha(archive)+"'",s)
s=s.replace('set_time_limit(180)','set_time_limit(300)').replace('$p->setTimeout(90)','$p->setTimeout(200)')
s=s.replace(" $verification=json_decode", " $run([$php,$root.'/artisan','migrate','--path=database/migrations/2026_10_21_000001_index_operations_sales_evidence.php','--force'],'migration.log',$backup);\n $run([$php,$root.'/artisan','reports:refresh','--max-seconds=180'],'sales-index-backfill.log',$backup);\n $verification=json_decode")
s=s.replace('No schema changes are included; no financial data is deleted.', 'Additive reporting schema and queue triggers remain on rollback; source financial data is never deleted.')
(d/'nuers-sales-performance-install.php').write_text(s)
print(json.dumps({'files':len(files),'sha256':sha(archive),'bytes':archive.stat().st_size}))
