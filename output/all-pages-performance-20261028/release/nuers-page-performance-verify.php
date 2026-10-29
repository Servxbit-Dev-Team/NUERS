<?php
if(PHP_SAPI!=='cli')exit(1);
umask(0077);$root=__DIR__.'/httpdocs';require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Schema};
if(parse_url(config('app.url'),PHP_URL_HOST)!=='nuers.net'||!config('security.enforce_privileged_mfa'))throw new RuntimeException('Wrong target or security policy');
$m=json_decode(file_get_contents(__DIR__.'/nuers-page-performance-stage-20261028/audit-release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
foreach($m['files']as$p=>$h)if(!is_file($root.'/'.$p)||!hash_equals($h,hash_file('sha256',$root.'/'.$p)))throw new RuntimeException('Installed checksum mismatch: '.$p);
$p=app(App\Services\LedgerProjection::class);if(!$p->ready())throw new RuntimeException('Reporting index not active');
if(DB::table('ledger_dirty_documents')->exists()||DB::table('ledger_documents')->whereNull('reconciliation_prefix')->exists())throw new RuntimeException('Reporting backfill incomplete');
foreach(['ledger_reconciliation_summary','ledger_transaction_summary','ledger_eis_order','ledger_eis_summary','ledger_id','ledger_merchant_overview']as$i)if(!Schema::hasIndex('ledger_documents',$i))throw new RuntimeException('Missing reporting index: '.$i);
if(app(App\Services\EisDelivery::class)->ready())throw new RuntimeException('Production BIR transport unexpectedly enabled');
// CLI-only internal read probes; no credentials, records or authorization policy are changed.
$probe=new class extends App\Http\Controllers\Api\OperationsReportController {protected function actor($r):object{return(object)['id'=>'cli-read-verification'];}};
$tx=new class extends App\Http\Controllers\Api\TransactionWorkspaceController {protected function actor($r):object{return(object)['id'=>'cli-read-verification'];}};
$results=[];
foreach([['reconciliation',['mode'=>'reconciliation']],['sales',['mode'=>'sales']],['transactions',[]],['reconciliation_type',['mode'=>'reconciliation','group'=>'type']],['reconciliation_payment',['mode'=>'reconciliation','group'=>'payment']],['reconciliation_branch',['mode'=>'reconciliation','group'=>'branch']]]as[$name,$params]){
 $r=Illuminate\Http\Request::create('/','GET',$params+['per_page'=>10]);$app->instance('request',$r);$c=$name==='transactions'?$tx:$probe;
 $t=microtime(true);$b=$c->index($r)->getData(true);$cold=round((microtime(true)-$t)*1000,2);
 $t=microtime(true);$again=$c->index($r)->getData(true);$warm=round((microtime(true)-$t)*1000,2);
 if(count($b['data'])>10||$b['total']!==$again['total'])throw new RuntimeException('Bounded report verification failed: '.$name);
 $results[$name]=['records'=>$b['total'],'page_rows'=>count($b['data']),'initial_ms'=>$cold,'cached_ms'=>$warm];
}
$center=app(App\Services\EisTransmissionCenter::class);$t=microtime(true);$total=$center->query([],true)->count();$selected=app(App\Services\ReportPagination::class)->page($center->query([]),$total,1,10,'business_date');$centerRows=$selected['ids']->isEmpty()?collect():$center->query([])->whereIn('id',$selected['ids'])->get();
$results['eis_center']=['records'=>$total,'page_rows'=>$centerRows->count(),'read_ms'=>round((microtime(true)-$t)*1000,2)];
$checked=0;foreach(DB::table('merchants')->orderBy('id')->limit(2)->pluck('id')as$id){$q=app(App\Services\IssuedDocumentLedger::class)->query($id);if((clone$q)->where('merchant_id','<>',$id)->exists())throw new RuntimeException('Tenant scope failed');$checked++;}
$audit=app(App\Services\AuditIntegritySnapshot::class)->verify();
echo json_encode(['verified'=>true,'release'=>$m['release'],'php'=>PHP_VERSION,'database'=>DB::selectOne('SELECT VERSION() AS v')->v,'files_verified'=>count($m['files']),'reports'=>$results,'projection_backlog'=>0,'missing_reconciliation_evidence'=>0,'merchant_scopes_checked'=>$checked,'mfa_enabled'=>true,'production_transport_blocked'=>true,'audit_integrity'=>$audit['valid'],'audit_checked_at'=>$audit['checked_at'],'verified_at'=>gmdate('c')]);
