<?php
// Reproducible, synthetic-only recovery drill. Never reads the project's .env.
declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use App\Services\PrivateDocumentStream;
use App\Services\MerchantWallet;
use App\Services\BirCompliance;

$root = dirname(__DIR__, 2);
$id = 'LAB-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
$lab = $root.'/.local/backup-dr/'.$id;
umask(0077);
foreach (['env','storage/framework/cache','storage/framework/sessions','storage/framework/views','storage/logs','primary/objects','backup/objects','key-escrow'] as $p) mkdir($lab.'/'.$p,0700,true);
$source = $lab.'/primary/database.sqlite'; touch($source);
$appKey = random_bytes(32); $backupKey = random_bytes(32); $manifestKey = random_bytes(32);
file_put_contents($lab.'/key-escrow/lab-keys.json',json_encode(['app'=>base64_encode($appKey),'backup'=>base64_encode($backupKey),'manifest'=>base64_encode($manifestKey)]));
$env = ['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode($appKey),'APP_URL'=>'http://localhost','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>$source,'DB_URL'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','BCRYPT_ROUNDS'=>'4','APP_CONFIG_CACHE'=>$lab.'/env/no-config.php','APP_ROUTES_CACHE'=>$lab.'/env/no-routes.php','APP_EVENTS_CACHE'=>$lab.'/env/no-events.php','LOG_CHANNEL'=>'null'];
foreach ($env as $k=>$v) {putenv($k.'='.$v);$_ENV[$k]=$v;$_SERVER[$k]=$v;}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useEnvironmentPath($lab.'/env'); $app->useStoragePath($lab.'/storage');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>$source,'database.connections.sqlite.url'=>null,'cache.default'=>'array','mail.default'=>'array','notifications.dispatch_enabled'=>false,'merchant_webhooks.delivery_enabled'=>false,'filesystems.disks.local.root'=>$lab.'/primary/objects','app.key'=>'base64:'.base64_encode($appKey)]);
DB::purge('sqlite'); Storage::forgetDisk('local');
function check(bool $yes,string $label): void {if (!$yes) throw new RuntimeException($label);}
function utc(): string {return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');}
function seconds(int $start): float {return round((hrtime(true)-$start)/1e9,6);}
check(config('database.connections.sqlite.database')===$source && str_starts_with($source,$root.'/.local/backup-dr/'),'Isolation guard');
check(Artisan::call('migrate',['--force'=>true])===0,'Fresh synthetic schema migration');
DB::statement('CREATE TABLE dr_lab_documents (id TEXT PRIMARY KEY, private_path TEXT, bytes INTEGER, sha256 TEXT)');
DB::statement('CREATE TABLE dr_lab_markers (id INTEGER PRIMARY KEY, committed_observed_at TEXT)');
$admin=App\Models\User::factory()->create(['email'=>'operator@dr-lab.invalid']);
$owner=App\Models\User::factory()->create(['email'=>'merchant@dr-lab.invalid']);
$merchant=(string)Str::uuid();
DB::table('profiles')->insert(['id'=>$owner->id,'email'=>$owner->email,'role'=>'merchant']);
DB::table('merchants')->insert(['id'=>$merchant,'merchant_account_id'=>$owner->id,'business_name'=>'NUERS SYNTHETIC RECOVERY TEST','tin'=>'000000000','status'=>'active','vat_registered'=>true]);
DB::transaction(fn()=>app(MerchantWallet::class)->credit($merchant,1000000,(string)Str::uuid(),'SYNTHETIC - NOT REAL MONEY',(string)$admin->id));
$append=function(int $n) use($merchant,$owner):void {
 DB::transaction(function()use($n,$merchant,$owner){
  $tid=(string)Str::uuid();$rid=(string)Str::uuid();$ref=sprintf('DR-SYNTH-%06d',$n);
  DB::table('merchant_transactions')->insert(['id'=>$tid,'merchant_id'=>$merchant,'transaction_ref'=>$ref,'amount'=>112,'vat_amount'=>12,'vatable_sales'=>100,'net_amount'=>112,'status'=>'completed','receipt_id'=>$rid,'created_at'=>now(),'updated_at'=>now()]);
  DB::table('transaction_receipts')->insert(['id'=>$rid,'transaction_id'=>$tid,'merchant_id'=>$merchant,'receipt_number'=>$ref,'merchant_name'=>'NUERS SYNTHETIC RECOVERY TEST','merchant_tin'=>'000000000','receipt_type'=>'sales_invoice','gross_amount'=>112,'vatable_sales'=>100,'vat_amount'=>12,'total_due'=>112,'items'=>json_encode([['description'=>'Synthetic recovery fixture','quantity'=>1,'price'=>112]]),'issued_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
  DB::table('integration_inbox')->insert(['id'=>(string)Str::uuid(),'merchant_id'=>$merchant,'api_key_id'=>'synthetic-no-real-key','namespace_hash'=>hash('sha256','ns-'.$n),'event_hash'=>hash('sha256','event-'.$n),'request_hash'=>hash('sha256','body-'.$n),'source_system'=>'recovery-lab','source_event_id'=>$ref,'external_reference'=>$ref,'branch_code'=>'00000','schema_version'=>'synthetic-dr-1','event_type'=>'source_document','occurred_at'=>now(),'received_at'=>now(),'transaction_id'=>$tid,'receipt_id'=>$rid,'response'=>json_encode(['synthetic'=>true]),'created_at'=>now(),'updated_at'=>now()]);
  app(MerchantWallet::class)->charge($merchant,11200,'sales_invoice','dr_lab',$tid,$ref,null,'synthetic',(string)$owner->id);
  app(BirCompliance::class)->audit('dr.lab.synthetic_record',$tid,(string)$owner->id,['synthetic'=>true,'ordinal'=>$n]);
 });
 DB::table('dr_lab_markers')->insert(['id'=>$n,'committed_observed_at'=>utc()]);
};
for($i=1;$i<=500;$i++)$append($i);
for($i=1;$i<=8;$i++){
 $raw=$lab.'/fixture-'.$i.'.bin';file_put_contents($raw,random_bytes(2*1024*1024));
 $row=['id'=>(string)Str::uuid(),'private_path'=>'fixture-'.$i.'.enc','bytes'=>filesize($raw),'sha256'=>hash_file('sha256',$raw)];
 app(PrivateDocumentStream::class)->store($raw,$row['private_path']);DB::table('dr_lab_documents')->insert($row);
}
// Quiesced cross-store checkpoint: no writers or workers run during capture.
$checkpoint=DB::table('dr_lab_markers')->orderByDesc('id')->first();
$counts=[];foreach(['merchant_transactions','transaction_receipts','integration_inbox','merchant_wallet_entries','eis_audit_events','dr_lab_documents'] as $t)$counts[$t]=DB::table($t)->count();
$wallet=app(MerchantWallet::class)->audit($merchant);check($wallet['valid'],'Source wallet audit');check(app(BirCompliance::class)->auditIntegrity(),'Source audit chain');
$backupStart=hrtime(true);$backupStarted=utc();DB::disconnect('sqlite');
$sqlite=new SQLite3($source,SQLITE3_OPEN_READONLY);$snapshot=new SQLite3($lab.'/snapshot.sqlite');check($sqlite->backup($snapshot),'SQLite consistent snapshot');$snapshot->close();$sqlite->close();
$crypt=new Encrypter($backupKey,'AES-256-CBC');
file_put_contents($lab.'/backup/database.enc',$crypt->encryptString(file_get_contents($lab.'/snapshot.sqlite')));
$files=['database.enc'=>hash_file('sha256',$lab.'/backup/database.enc')];
foreach(glob($lab.'/primary/objects/*.enc') as $path){$dest='objects/'.basename($path);check(copy($path,$lab.'/backup/'.$dest),'Backup object copy');$files[$dest]=hash_file('sha256',$lab.'/backup/'.$dest);}
$manifest=['test_id'=>$id,'checkpoint'=>$checkpoint,'counts'=>$counts,'wallet'=>$wallet,'database_sha256'=>hash_file('sha256',$lab.'/snapshot.sqlite'),'files'=>$files];
$json=json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);file_put_contents($lab.'/backup/manifest.json',$json);file_put_contents($lab.'/backup/manifest.hmac',hash_hmac('sha256',$json,$manifestKey));
$backupSeconds=seconds($backupStart);$backupBytes=0;foreach(array_keys($files)as$f)$backupBytes+=filesize($lab.'/backup/'.$f);
$restore=function(string $label,object $latest)use($lab,$source,$merchant,$manifest,$counts,$wallet):array{
 $start=hrtime(true);$outage=utc();$target=$lab.'/restore-'.$label;mkdir($target,0700);mkdir($target.'/objects',0700);
 DB::disconnect('sqlite');check(rename($source,$source.'.unavailable'),'Simulate source unavailability');
 try{
  $keys=json_decode(file_get_contents($lab.'/key-escrow/lab-keys.json'),true,512,JSON_THROW_ON_ERROR);
  $raw=file_get_contents($lab.'/backup/manifest.json');check(hash_equals(file_get_contents($lab.'/backup/manifest.hmac'),hash_hmac('sha256',$raw,base64_decode($keys['manifest']))),'Manifest authentication');
  $m=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
  foreach($m['files']as$f=>$hash)check(hash_equals($hash,hash_file('sha256',$lab.'/backup/'.$f)),'Backup object hash');
  $d=new Encrypter(base64_decode($keys['backup']),'AES-256-CBC');
  file_put_contents($target.'/database.sqlite',$d->decryptString(file_get_contents($lab.'/backup/database.enc')));
  check(hash_equals($m['database_sha256'],hash_file('sha256',$target.'/database.sqlite')),'Restored DB checksum');
  foreach(array_keys($m['files'])as$f)if(str_starts_with($f,'objects/'))check(copy($lab.'/backup/'.$f,$target.'/'.$f),'Restore object copy');
  $copySeconds=seconds($start);
  config(['database.connections.sqlite.database'=>$target.'/database.sqlite','filesystems.disks.local.root'=>$target.'/objects']);DB::purge('sqlite');Storage::forgetDisk('local');
  Crypt::swap(new Encrypter(base64_decode($keys['app']),'AES-256-CBC'));
  check(DB::selectOne('PRAGMA integrity_check')->integrity_check==='ok','SQLite integrity');
  foreach($counts as$t=>$n)check(DB::table($t)->count()===$n,'Restored count '.$t);
  check(DB::table('merchant_transactions')->sum('amount')==56000,'Sales total');
  check(DB::table('transaction_receipts')->sum('total_due')==56000,'Receipt total');
  check(DB::table('merchant_transactions as t')->leftJoin('transaction_receipts as r','r.id','=','t.receipt_id')->whereNull('r.id')->count()===0,'Receipt linkage');
  check(app(MerchantWallet::class)->audit($merchant)===$wallet,'Complete wallet ledger');
  check(app(BirCompliance::class)->auditIntegrity(),'Audit chain');
  $objects=0;foreach(DB::table('dr_lab_documents')->get()as$row){$stream=app(PrivateDocumentStream::class)->verified($row);fclose($stream);$objects++;}
  $restored=DB::table('dr_lab_markers')->orderByDesc('id')->first();
  check((int)$restored->id===500,'Checkpoint watermark');
  $rpo=round((new DateTimeImmutable($latest->committed_observed_at))->format('U.u')-(new DateTimeImmutable($restored->committed_observed_at))->format('U.u'),6);
  return ['run'=>$label,'outage_at_utc'=>$outage,'recovered_at_utc'=>utc(),'rto_seconds'=>seconds($start),'copy_and_key_recovery_seconds'=>$copySeconds,'validation_seconds'=>round(seconds($start)-$copySeconds,6),'source_commit_observed_at_utc'=>$latest->committed_observed_at,'restored_commit_observed_at_utc'=>$restored->committed_observed_at,'rpo_seconds'=>$rpo,'source_sequence'=>(int)$latest->id,'restored_sequence'=>(int)$restored->id,'records_not_recovered'=>(int)$latest->id-(int)$restored->id,'private_objects_verified'=>$objects,'integrity_checks'=>'passed','restored_wallet_centavos'=>$wallet['balance_centavos']];
 }finally{DB::disconnect('sqlite');rename($source.'.unavailable',$source);config(['database.connections.sqlite.database'=>$source,'filesystems.disks.local.root'=>$lab.'/primary/objects']);DB::purge('sqlite');Storage::forgetDisk('local');}
};
$runs=[];for($i=1;$i<=3;$i++)$runs[]=$restore('A'.$i,$checkpoint);
usleep(2000000);for($i=501;$i<=503;$i++)$append($i);
$runs[]=$restore('B1',DB::table('dr_lab_markers')->orderByDesc('id')->first());
// Negative checks on disposable copies only. No restored target or original is edited.
$negative=[];
try{(new Encrypter(random_bytes(32),'AES-256-CBC'))->decryptString(file_get_contents($lab.'/backup/database.enc'));$negative['wrong_backup_key']='FAIL';}catch(Throwable $e){$negative['wrong_backup_key']='rejected';}
$bad=file_get_contents($lab.'/backup/database.enc');$bad[100]=$bad[100]==='A'?'B':'A';
try{$crypt->decryptString($bad);$negative['tampered_database_ciphertext']='FAIL';}catch(Throwable $e){$negative['tampered_database_ciphertext']='rejected';}
$row=DB::table('dr_lab_documents')->first();
$fake=clone$row;$fake->private_path='missing-synthetic-object.enc';
try{app(PrivateDocumentStream::class)->verified($fake);$negative['missing_private_object']='FAIL';}catch(Throwable $e){$negative['missing_private_object']='rejected';}
$fake=clone$row;$fake->sha256=str_repeat('0',64);
try{app(PrivateDocumentStream::class)->verified($fake);$negative['incorrect_plaintext_checksum']='FAIL';}catch(Throwable $e){$negative['incorrect_plaintext_checksum']='rejected';}
check(!in_array('FAIL',$negative,true),'All negative controls reject');
$sourceHashes=[];foreach(['app/Services/PrivateDocumentStream.php','app/Services/MerchantWallet.php','app/Services/BirCompliance.php','app/Services/BackupRecovery.php','app/Http/Controllers/Api/BackupRecoveryController.php','composer.lock']as$f)$sourceHashes[$f]=hash_file('sha256',$root.'/'.$f);
$result=['test_id'=>$id,'classification'=>'ISOLATED SYNTHETIC LOCAL RESTORE - NOT PRODUCTION DR CERTIFICATION','executed_at_utc'=>utc(),'software_version'=>'1.0.0','php_version'=>PHP_VERSION,'sqlite_version'=>SQLite3::version()['versionString'],'os'=>PHP_OS_FAMILY.' '.php_uname('r').' '.php_uname('m'),'dataset'=>['transactions'=>500,'receipts'=>500,'intake_identity_rows'=>500,'wallet_entries'=>$wallet['entries'],'audit_events'=>$counts['eis_audit_events'],'files'=>8,'plaintext_file_bytes'=>16777216,'database_bytes'=>filesize($lab.'/snapshot.sqlite'),'encrypted_backup_bytes'=>$backupBytes,'synthetic_sales_php'=>56000,'synthetic_wallet_balance_centavos'=>$wallet['balance_centavos']],'backup'=>['started_at_utc'=>$backupStarted,'elapsed_seconds'=>$backupSeconds,'manifest_sha256'=>hash('sha256',$json),'database_sha256'=>$manifest['database_sha256']],'runs'=>$runs,'negative_checks'=>$negative,'scope'=>['full_migrated_application_schema','fixture_rows_not_HTTP_intake','real_wallet_service_hash_chain','real_audit_service_hash_chain','real_private_file_encryption_and_verification','separate_lab_key_escrow_read','same_machine_local_disk','no_external_requests_or_messages','no_production_database_or_credentials','no_live_provider_failover','no_PITR_log_replay','no_browser_or_network_service_recovery','not_independently_reviewed'],'source_hashes'=>$sourceHashes,'script_sha256'=>hash_file('sha256',__FILE__)];
file_put_contents(__DIR__.'/lab-results.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
file_put_contents($lab.'/results.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo json_encode(['test_id'=>$id,'dataset'=>$result['dataset'],'backup_seconds'=>$backupSeconds,'runs'=>$runs,'negative_checks'=>$negative],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
