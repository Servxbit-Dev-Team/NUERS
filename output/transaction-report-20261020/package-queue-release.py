from pathlib import Path
import hashlib,json,zipfile
root=Path.cwd();out=root/'output/transaction-report-20261020';release=out/'queue-release';release.mkdir(exist_ok=True)
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
old=json.loads((out/'baseline.json').read_text());main=json.loads((out/'release/manifest.json').read_text());old.update(main['files']);old['app/Services/DocumentPdf.php']='dfb70f67f393d4033e12d45787a16679ff767a4afb122e9f8a27438c9caece58'
paths=['app/Services/TransactionPdfQueue.php','app/Services/TransactionReportExport.php','app/Services/DocumentPdf.php','app/Http/Controllers/Api/TransactionWorkspaceController.php','app/Console/Commands/BuildSalesExport.php','routes/api.php','src/pages/operations/transactions.tsx','src/components/merchant/environment-records.tsx','src/lib/transaction-export.ts','docs/knowledge/admin-transaction-reports.md']
files={p:root/p for p in paths}
for d in ['public/build','dist']:
 for p in (root/d).rglob('*'):
  if p.is_file() and old.get(str(p.relative_to(root)))!=sha(p):files[str(p.relative_to(root))]=p
for name,p in list(files.items()):
 if name.startswith('dist/assets/'):files[name[5:]]=p
 if name=='dist/index.html':files['index.html']=p
baseline={p:old[p] for p in files if p in old}
manifest={'release':'2026-10-20.transaction-pdf-worker.1','purpose':'Private encrypted transaction report worker and protected download','files':{n:sha(p) for n,p in files.items()},'baseline':baseline,'new_files':[p for p in files if p not in baseline],'schema_migration':False}
(release/'manifest.json').write_text(json.dumps(manifest,indent=2))
archive=release/'nuers-transaction-pdf-worker-20261020.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('audit-release-manifest.json',json.dumps(manifest))
 for name,p in files.items():z.write(p,name)
s=(out/'release/nuers-transaction-reports-install.php').read_text().replace('nuers-transaction-reports','nuers-transaction-pdf-worker').replace('6b1d4feb95bd21d8edbfd3c1c4f62eefa2773a377f3dd785c8cae38830e3b374',sha(archive)).replace('Transaction register and PDF exports','Private transaction PDF worker')
(release/'nuers-transaction-pdf-worker-install.php').write_text(s)
s=(out/'release/nuers-transaction-reports-verify.php').read_text().replace('nuers-transaction-reports','nuers-transaction-pdf-worker')
a=s.index(' $pdf=app(App\\Services\\DocumentPdf::class)');b=s.index(" if(!str_starts_with($pdf",a)
s=s[:a]+''' $actor=DB::table('users')->join('profiles','profiles.id','=','users.id')->whereIn('profiles.role',['admin','super_admin'])->select('users.id')->first();if(!$actor)throw new RuntimeException('No authorized report actor');
 $tmp=storage_path('app/private/transaction-export-verification-'.Illuminate\\Support\\Str::uuid());config(['document_pdf.export_directory'=>$tmp]);
 try {
  $queue=app(App\\Services\\TransactionPdfQueue::class);$pending=$queue->request($report,'NUERS-SYNTHETIC-PRIVATE-CHECK',$actor);
  $id=$pending->getData(true)['export_id'];if($pending->getStatusCode()!==202)throw new RuntimeException('Queue request failed');
  if(str_contains(file_get_contents($tmp.'/'.$id.'.input'),'SYNTHETIC PRIVATE RUNTIME CHECK'))throw new RuntimeException('Input not encrypted');
  $queue->runOne();$ready=$queue->download($id,$actor);$pdf=$ready->getContent();
  if($ready->getStatusCode()!==200)throw new RuntimeException('Queue render failed');
 } finally {Illuminate\\Support\\Facades\\File::deleteDirectory($tmp);}
''' + s[b:]
s=s.replace("'branded_pdf_render'=>true", "'branded_pdf_render'=>true,'encrypted_queue_render'=>true,'private_download'=>true")
(release/'nuers-transaction-pdf-worker-verify.php').write_text(s)
print(json.dumps({'files':len(files),'sha256':sha(archive),'archive':str(archive),'bytes':archive.stat().st_size},indent=2))
