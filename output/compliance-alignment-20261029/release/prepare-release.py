from pathlib import Path
import json,hashlib,zipfile
out=Path('output/compliance-alignment-20261029/release')
c=json.loads((out/'candidates.json').read_text()); live=json.loads((out/'live-baseline.json').read_text())['files']
files={d:s for d,s in c.items() if live[d]!=hashlib.sha256(Path(s).read_bytes()).hexdigest()}
manifest={'release':'2026-10-29.compliance-operations.1','schema_migration':True,'files':{d:hashlib.sha256(Path(s).read_bytes()).hexdigest() for d,s in files.items()},'baseline':{d:live[d] for d in files},'new_files':[d for d in files if live[d] is None]}
(out/'audit-release-manifest.json').write_text(json.dumps(manifest,indent=2))
package=out/'nuers-compliance-20261029.zip'
with zipfile.ZipFile(package,'w',zipfile.ZIP_DEFLATED,compresslevel=6) as z:
 for d,s in files.items():z.write(s,d)
 z.write(out/'audit-release-manifest.json','audit-release-manifest.json')
sha=hashlib.sha256(package.read_bytes()).hexdigest()
s=Path('output/bir-integration-20261028/release/nuers-bir-wire-install.php').read_text().replace('nuers-bir-wire','nuers-compliance').replace('20261028','20261029').replace('563be3051ddc2d114da5310530aff017fb7e8c9312cd645fb4eeab137b5874d8',sha).replace('2026_10_28_000003_create_bir_eis_wire_transport.php','2026_10_29_000001_align_taxpayer_and_compliance_workflows.php').replace('BIR EIS credential-readiness update','BIR technical alignment update').replace('// Additive BIR transport tables remain on rollback; no application or source data is deleted.', '// New compliance tables and nullable fields remain on code rollback; no source data is deleted.')
s=s.replace("if(!config('security.enforce_privileged_mfa'))", "if(config('bir_compliance.source_capture_accreditation_enforced',false))throw new RuntimeException('Existing intake must remain available');\nif(!config('security.enforce_privileged_mfa'))")
start=s.index('$snapshot=function()');end=s.index("if(file_exists($root.'/storage/framework/down')",start)
s=s[:start]+'''$tables=['merchants'=>'id','merchant_wallets'=>'merchant_id','merchant_wallet_entries'=>'id','merchant_wallet_topups'=>'id','profiles'=>'id','merchant_api_keys'=>'id','merchant_verification_documents'=>'id','transaction_receipts'=>'id','business_invoices'=>'id','merchant_transactions'=>'id','eis_integrations'=>'id','invoice_payloads'=>'id','transmissions'=>'id','pti_applications'=>'id','taxpayer_authorizations'=>'id'];
$columns=[];foreach($tables as$t=>$key)if(Schema::hasTable($t))$columns[$t]=Schema::getColumnListing($t);
$snapshot=function()use($root,$tables,$columns){$r=['env'=>hash_file('sha256',$root.'/.env'),'security'=>hash('sha256',json_encode(config('security')))];foreach($columns as$t=>$cols){$key=$tables[$t];$h=hash_init('sha256');foreach(DB::table($t)->select($cols)->orderBy($key)->lazyById(200,$key) as$row)hash_update($h,json_encode($row)."\\n");$r[$t]=hash_final($h);}return$r;};
''' + s[end:]
needle=" // Promote entry manifests last. Existing hashed assets remain available."
backup=''' // Private consistent recovery backup. Credentials remain in the child environment, never in command output.
 $db=DB::connection()->getConfig();
 if(!in_array($db['driver'],['mysql','mariadb'],true))throw new RuntimeException('Unexpected database driver');
 $args=['/usr/bin/mysqldump','--single-transaction','--quick','--skip-lock-tables','--no-tablespaces','--hex-blob','--host='.$db['host'],'--port='.($db['port']??3306),'--user='.$db['username'],'--result-file='.$backup.'/database.sql',$db['database']];
 if(!empty($db['unix_socket']))array_splice($args,-1,0,['--socket='.$db['unix_socket']]);
 $dump=new Symfony\\Component\\Process\\Process($args,$root,['MYSQL_PWD'=>$db['password']]);$dump->setTimeout(300);$dump->run();
 if(!$dump->isSuccessful()||!is_file($backup.'/database.sql')||filesize($backup.'/database.sql')<1000)throw new RuntimeException('Private database backup failed; deployment stopped');
 chmod($backup.'/database.sql',0600);file_put_contents($backup.'/database.sha256',hash_file('sha256',$backup.'/database.sql'));
'''
s=s.replace(needle,backup+needle)
(out/'nuers-compliance-install.php').write_text(s)
print(json.dumps({'files':len(files),'package_bytes':package.stat().st_size,'sha256':sha}))
