from pathlib import Path
import hashlib,json,zipfile,re,sys
root=Path.cwd();out=root/'output/bir-integration-20261028';dest=out/'release'
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
names=['app/Http/Controllers/Api/BirWireController.php', 'app/Http/Controllers/Api/BirCallbackController.php', 'app/Services/Eis/Protocol.php', 'app/Services/Eis/InvoiceMapper.php', 'app/Services/Eis/ResultVerifier.php', 'app/Services/Eis/WireClient.php', 'app/Services/Eis/WireBatches.php', 'app/Services/Eis/WireProjection.php', 'app/Services/EisDocumentReadiness.php', 'app/Services/EisDelivery.php', 'app/Services/BirCompliance.php', 'app/Services/SubmissionEvidence.php', 'app/Services/TransactionTransmissionQuery.php', 'app/Http/Middleware/ApiBoundary.php', 'config/bir_eis.php', 'database/migrations/2026_10_28_000003_create_bir_eis_wire_transport.php', 'routes/api.php', 'routes/console.php', 'src/components/admin/bir-wire.tsx', 'src/components/admin/bir-wire.css', 'src/pages/integrations/index.tsx', 'src/pages/operations/bir-transmission-center.tsx', 'docs/knowledge/README.md', 'docs/knowledge/bir-eis-credential-readiness.md']
files={n:root/n for n in names}
for p in (root/'public/build').rglob('*'):
 if p.is_file():files[str(p.relative_to(root))]=p
for p in (root/'dist').rglob('*'):
 if not p.is_file():continue
 n=str(p.relative_to(root))
 if n.startswith('dist/assets/'):
  files[n]=p;files[n[5:]]=p
 elif n=='dist/index.html':files[n]=p;files['index.html']=p
if len(sys.argv)>1 and sys.argv[1]=='candidates':
 (out/'candidate-paths.json').write_text(json.dumps(list(files)));print(len(files));sys.exit()
old=json.loads((out/'live-baseline.json').read_text())
original=json.loads((out/'baseline.json').read_text())
for n,h in original.items():
 if n in names and old[n]!=h and not (n=='docs/knowledge/README.md' and old[n] is None):raise RuntimeError('Source drift: '+n)
for n in names:
 if n not in original and old[n] is not None and old[n]!=sha(root/n):raise RuntimeError('Unexpected existing module: '+n)
files={n:p for n,p in files.items() if old[n]!=sha(p)}
manifest={'release':'2026-10-28.bir-wire.1','purpose':'Credential-ready BIR EIS adapter, company authority linkage and evidence-verified batches; outbound and callbacks disabled','files':{n:sha(p) for n,p in files.items()},'baseline':{n:old[n] for n in files if old[n] is not None},'new_files':[n for n in files if old[n] is None],'schema_migration':True}
(dest/'manifest.json').write_text(json.dumps(manifest,indent=2))
archive=dest/'nuers-bir-wire-20261028.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('audit-release-manifest.json',json.dumps(manifest))
 for n,p in files.items():z.write(p,n)
s=(root/'output/pti-applications-20261028/release/nuers-pti-applications-install.php').read_text().replace('nuers-pti-applications','nuers-bir-wire').replace('PTI application preparation update','BIR EIS credential-readiness update')
s=re.sub(r"\$sha='[a-f0-9]{64}'", "$sha='"+sha(archive)+"'",s)
s=s.replace('2026_10_28_000002_create_pti_applications.php','2026_10_28_000003_create_bir_eis_wire_transport.php')
s=s.replace(" $run([$php,$root.'/artisan','reports:refresh','--max-seconds=180'],'sales-index-backfill.log',$backup);\n",'')
s=s.replace('// Additive reporting schema and queue triggers remain on rollback; source financial data is never deleted. Previous routes and manifests are restored.','// Additive PTI tables remain on rollback; no application or source data is deleted. Previous routes and manifests are restored.')
s=s.replace("'merchant_transactions'=>'id'", "'merchant_transactions'=>'id','eis_integrations'=>'id','invoice_payloads'=>'id','transmissions'=>'id','pti_applications'=>'id'")
s=s.replace('Additive PTI tables', 'Additive BIR transport tables')
s=s.replace("if(!config('security.enforce_privileged_mfa'))", "if(config('bir_eis.outbound_enabled',false)||config('bir_eis.callback_enabled',false))throw new RuntimeException('BIR gates must remain disabled');\nif(!config('security.enforce_privileged_mfa'))")
(dest/'nuers-bir-wire-install.php').write_text(s)
print(json.dumps({'files':len(files),'bytes':archive.stat().st_size,'sha256':sha(archive)}))
