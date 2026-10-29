<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
use App\Services\SalesExportQueue;
$m=DB::table('merchants')->where('id','lab-merchant-a')->first();
$service=app(SalesExportQueue::class);
$job=$service->request($m,$m->merchant_account_id,['from'=>'2026-09-21','to'=>'2026-10-20']);
$start=microtime(true);memory_reset_peak_usage();$service->runOne();
$row=DB::table('sales_report_exports')->where('id',$job->id)->first();
$result=['state'=>$row->state,'records'=>(int)$row->records,'seconds'=>round(microtime(true)-$start,3),'peak_mb'=>round(memory_get_peak_usage(true)/1048576,2),'file_bytes'=>is_file($service->path($row->id))?filesize($service->path($row->id)):0,'sha256'=>$row->sha256];
file_put_contents(__DIR__.'/export-result.json',json_encode($result,JSON_PRETTY_PRINT));echo json_encode($result)."\n";
if($row->state==='ready'&&$row->records==1000000){unlink($service->path($row->id));echo "Synthetic export artifact verified and removed.\n";}else exit(1);
