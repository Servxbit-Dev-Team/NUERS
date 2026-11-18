<?php
/** Synthetic socket-only restore drill. Never deploy or run against production. */
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{Artisan, DB, Crypt, Hash};
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use App\Services\{LocalRecoveryBackup, RecoveryArchive};
function check(bool $v, string $why): void { if (!$v) throw new RuntimeException($why); }
$socket = '/drill/mysql.sock';
check(app()->environment('testing') && getenv('NUERS_SYNTHETIC_RESTORE') === '1' && is_file('/drill/ISOLATED_TEST_ONLY'), 'Isolated restore guard');
$start = microtime(true); $suffix = bin2hex(random_bytes(6));
$source = 'nuers_drill_src_'.$suffix; $target = 'nuers_drill_dst_'.$suffix;
$pdo = new PDO('mysql:unix_socket='.$socket, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
check(strtoupper($pdo->query('SELECT @@event_scheduler')->fetchColumn()) === 'OFF', 'Event scheduler must stay off');
foreach ([$source,$target] as $db) $pdo->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root = '/drill/run-'.$suffix; mkdir($root,0700); mkdir($root.'/archives',0700); mkdir($root.'/storage/app/private',0700,true); mkdir($root.'/storage/app/public',0700,true);mkdir($root.'/storage/logs',0700,true);
$app->useStoragePath($root.'/storage'); $key=random_bytes(32);file_put_contents($root.'/recovery.key',$key);chmod($root.'/recovery.key',0600);
config(['database.default'=>'mysql','database.connections.mysql.host'=>'localhost','database.connections.mysql.unix_socket'=>$socket,'database.connections.mysql.database'=>$source,'database.connections.mysql.username'=>'root','database.connections.mysql.password'=>'','database.connections.mysql.url'=>null,'cache.default'=>'array','session.driver'=>'array','queue.default'=>'sync','mail.default'=>'array','notifications.dispatch_enabled'=>false,'bir_eis.outbound_enabled'=>false,'operations.backup.enabled'=>true,'operations.backup.directory'=>$root.'/archives','operations.backup.key_file'=>$root.'/recovery.key','operations.backup.mysqldump'=>'/usr/bin/mariadb-dump','operations.backup.max_bytes'=>64*1024*1024,'operations.minimum_free_bytes'=>1024*1024]);
DB::purge('mysql');Artisan::call('migrate',['--force'=>true]);
$secret='SYNTHETIC-RECOVERY-CHECK'; $merchants=[];
foreach (['super_admin','admin','bir','rdo','merchant','client','consumer'] as $role) {
 $u=App\Models\User::factory()->create(['email'=>$role.'@example.test','password'=>'Synthetic-Recovery-Only-123']);
 $u->forceFill(['mfa_secret'=>Crypt::encryptString($secret)])->save();DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'role'=>$role]);
 if ($role==='merchant') { $m=(string)Str::uuid();$merchants[]=$m;DB::table('merchants')->insert(['id'=>$m,'merchant_account_id'=>$u->id,'business_name'=>'Synthetic recovery business','tin'=>'999999999000','status'=>'active']); }
}
$m=$merchants[0];$invoice=(string)Str::uuid();DB::table('business_invoices')->insert(['id'=>$invoice,'merchant_id'=>$m,'invoice_number'=>'DRILL-'.$suffix,'document_type'=>'vat_invoice','merchant_name'=>'Synthetic recovery business','merchant_tin'=>'999999999000','merchant_address'=>'Synthetic only','seller_branch_code'=>'00000','buyer_name'=>'Synthetic buyer','status'=>'issued','issue_date'=>now()->toDateString(),'total_amount'=>112,'subtotal_amount'=>112,'taxable_amount'=>100,'vat_amount'=>12,'vat_registered'=>true,'prices_include_vat'=>true,'amount_due'=>112]);
DB::table('business_invoice_items')->insert(['id'=>(string)Str::uuid(),'invoice_id'=>$invoice,'merchant_id'=>$m,'line_number'=>1,'description'=>'Synthetic restored item','quantity'=>1,'unit_price'=>112,'line_total'=>112,'taxable_amount'=>100,'vat_amount'=>12,'vat_rate'=>12,'tax_type'=>'vatable']);
DB::statement('CREATE TABLE assurance_probe (id INT PRIMARY KEY, amount DECIMAL(12,2) NOT NULL, note VARCHAR(80)) ENGINE=InnoDB');
DB::statement("INSERT INTO assurance_probe VALUES (1,112.00,'synthetic')");
DB::unprepared('CREATE TRIGGER assurance_probe_insert BEFORE INSERT ON assurance_probe FOR EACH ROW SET NEW.note = UPPER(NEW.note)');
DB::unprepared('CREATE PROCEDURE assurance_probe_total() SELECT SUM(amount) AS total FROM assurance_probe');
DB::unprepared("CREATE EVENT assurance_probe_event ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 30 DAY DISABLE DO INSERT INTO assurance_probe VALUES (99,0,'event')");
$object=random_bytes(64000);file_put_contents(storage_path('app/private/'.$invoice.'.bin'),$object);
function inventory():array { $r=[];foreach(DB::select('SHOW FULL TABLES WHERE Table_type = ?', ['BASE TABLE'])as $t){$name=array_values((array)$t)[0];$rows=array_map(function($x){$v=(array)$x;ksort($v);return json_encode($v,JSON_THROW_ON_ERROR);},DB::select('SELECT * FROM `'.str_replace('`','``',$name).'`'));sort($rows);$r[$name]=['rows'=>count($rows),'sha256'=>hash('sha256',implode("\n",$rows))];}ksort($r);return $r;}
function databaseObjects(): array {
 $schema=DB::connection()->getDatabaseName();$result=[];
 foreach (['triggers'=>['TRIGGERS','TRIGGER_SCHEMA','TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT'],'routines'=>['ROUTINES','ROUTINE_SCHEMA','ROUTINE_NAME,ROUTINE_TYPE,ROUTINE_DEFINITION,SECURITY_TYPE,SQL_DATA_ACCESS'],'events'=>['EVENTS','EVENT_SCHEMA','EVENT_NAME,EVENT_DEFINITION,EVENT_TYPE,EXECUTE_AT,INTERVAL_VALUE,INTERVAL_FIELD,STATUS']] as $type=>[$table,$column,$fields]) {
  $rows=array_map(fn($row)=>str_replace($schema,'__DRILL_SCHEMA__',json_encode((array)$row,JSON_THROW_ON_ERROR)),DB::select('SELECT '.$fields.' FROM information_schema.'.$table.' WHERE '.$column.' = ?',[$schema]));sort($rows);$result[$type]=['count'=>count($rows),'sha256'=>hash('sha256',implode("\n",$rows))];
 }return $result;
}
$expectedObjects=databaseObjects();
$expected=inventory();$commitAt=microtime(true);$backup=app(LocalRecoveryBackup::class)->create();$recoveryStart=microtime(true);
$restored=$root.'/recovered';$manifest=app(RecoveryArchive::class)->restore($root.'/archives/'.$backup['archive_id'].'.nuers',$restored,$key);
$cnf=$root.'/restore.cnf';file_put_contents($cnf,"[client]\nuser=root\nsocket=".$socket."\n");chmod($cnf,0600);
$input=fopen($restored.'/database.sql','rb');$process=new Process(['/usr/bin/mariadb','--defaults-extra-file='.$cnf,'--',$target]);$process->setInput($input);$process->setTimeout(180);$process->run();fclose($input);unlink($cnf);check($process->isSuccessful(),'Isolated SQL import failed');
config(['database.connections.mysql.database'=>$target]);DB::purge('mysql');check(inventory()===$expected,'Restored table inventory differs');check(databaseObjects()===$expectedObjects,'Restored database object definitions differ');
check(hash_equals(hash('sha256',$object),hash_file('sha256',$restored.'/objects/private/'.$invoice.'.bin')),'Restored object hash differs');
check(DB::table('profiles')->count()===7,'Seven roles not restored');
foreach(App\Models\User::all()as $u){check(Hash::check('Synthetic-Recovery-Only-123',$u->password),'Password hash not recovered');check(Crypt::decryptString($u->mfa_secret)===$secret,'Encrypted record not recovered with separate application key');}
check((float)DB::table('business_invoices')->sum('vat_amount')===12.0 && (float)DB::table('business_invoice_items')->sum('line_total')===112.0,'Financial reconciliation failed');
check(count(DB::select("SHOW TRIGGERS WHERE `Trigger` = 'assurance_probe_insert'"))===1,'Trigger not restored');check(count(DB::select("SHOW PROCEDURE STATUS WHERE Db = ? AND Name = 'assurance_probe_total'",[$target]))===1,'Routine not restored');check(count(DB::select("SHOW EVENTS WHERE Name = 'assurance_probe_event'"))===1,'Event not restored');
DB::table('assurance_probe')->insert(['id'=>2,'amount'=>1,'note'=>'restored']);check(DB::table('assurance_probe')->where('id',2)->value('note')==='RESTORED','Restored trigger does not execute');check((float)DB::select('CALL assurance_probe_total()')[0]->total===113.0,'Restored routine does not execute');
check(strtoupper(DB::selectOne('SELECT @@event_scheduler AS state')->state)==='OFF','Event scheduler changed');check(DB::table('assurance_probe')->where('id',99)->doesntExist(),'Disabled event executed');
$r=['synthetic_only'=>true,'production_restore_verified'=>false,'database_driver'=>'mysql','tables_verified'=>count($expected),'roles_restored'=>7,'database_objects_verified'=>$expectedObjects,'object_hash_verified'=>true,'financial_reconciliation'=>true,'encrypted_records_verified'=>true,'trigger_routine_event_restored'=>true,'event_execution_disabled'=>true,'archive_authenticated'=>true,'archive_bytes'=>$backup['size_bytes'],'capture_seconds'=>$backup['duration_seconds'],'restore_seconds'=>round(microtime(true)-$recoveryStart,3),'total_seconds'=>round(microtime(true)-$start,3),'synthetic_lost_records'=>0,'limitation'=>'Socket-only synthetic MariaDB drill. Not an AWS RDS/EFS production snapshot restore or production RTO/RPO measurement.'];
file_put_contents('/drill/restore-result.json',json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode($r,JSON_THROW_ON_ERROR)."\n";
