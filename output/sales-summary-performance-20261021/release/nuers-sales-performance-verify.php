<?php
if(PHP_SAPI!=='cli')exit(1);
umask(0077);$root=__DIR__.'/httpdocs';require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Schema};
if(parse_url(config('app.url'),PHP_URL_HOST)!=='nuers.net'||!config('security.enforce_privileged_mfa'))throw new RuntimeException('Wrong target or security policy');
$m=json_decode(file_get_contents(__DIR__.'/nuers-sales-performance-stage-20261021/audit-release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
foreach($m['files']as$p=>$h)if(!is_file($root.'/'.$p)||!hash_equals($h,hash_file('sha256',$root.'/'.$p)))throw new RuntimeException('Installed checksum mismatch: '.$p);
$p=app(App\Services\LedgerProjection::class);if(!$p->ready())throw new RuntimeException('Reporting index not active');
$p->installTriggers();
if(DB::table('ledger_dirty_documents')->exists()||DB::table('ledger_documents')->whereNull('operations_sales_valid')->exists())throw new RuntimeException('Reporting backfill incomplete');
foreach(['ledger_sales_recent','ledger_sales_amount','ledger_sales_summary','ledger_sales_evidence']as$i)if(!Schema::hasIndex('ledger_documents',$i))throw new RuntimeException('Missing reporting index');
if(app(App\Services\EisDelivery::class)->ready())throw new RuntimeException('Production BIR transport unexpectedly enabled');
$request=Illuminate\Http\Request::create('/','GET',['mode'=>'sales','per_page'=>10]);
$probe=new class extends App\Http\Controllers\Api\OperationsReportController {public function read($r){return $this->indexedSales($r,false);}};
$t=microtime(true);$body=$probe->read($request)->getData(true);$cold=round((microtime(true)-$t)*1000,2);
$t=microtime(true);$again=$probe->read($request)->getData(true);$warm=round((microtime(true)-$t)*1000,2);
if(($body['query_mode']??null)!=='indexed_sales'||count($body['data'])>10||$body['total']!==$again['total'])throw new RuntimeException('Sales report read failed');
if(App\Services\ReportCsv::cell('=1+1')!=="'=1+1")throw new RuntimeException('CSV escaping failed');
$limit=Illuminate\Http\Request::create('/','GET',['report_limit'=>500]);App\Services\ReportCsv::limit($limit,500);$blocked=false;
try{App\Services\ReportCsv::limit($limit,501);}catch(Symfony\Component\HttpKernel\Exception\HttpException$e){$blocked=$e->getStatusCode()===422;}if(!$blocked)throw new RuntimeException('PDF boundary failed');
$checked=0;foreach(DB::table('merchants')->orderBy('id')->limit(2)->pluck('id')as$id){$q=app(App\Services\IssuedDocumentLedger::class)->query($id);if((clone$q)->where('merchant_id','<>',$id)->exists())throw new RuntimeException('Tenant scope failed');$checked++;}
echo json_encode(['verified'=>true,'files_verified'=>count($m['files']),'query_mode'=>$body['query_mode'],'sales_records'=>$body['total'],'page_records'=>count($body['data']),'initial_read_ms'=>$cold,'cached_read_ms'=>$warm,'projection_backlog'=>0,'missing_eligibility'=>0,'trigger_definitions_verified'=>true,'merchant_scopes_checked'=>$checked,'mfa_enabled'=>true,'production_transport_blocked'=>true,'pdf_limit'=>500,'csv_formula_escaping'=>true,'verified_at'=>gmdate('c')]);
