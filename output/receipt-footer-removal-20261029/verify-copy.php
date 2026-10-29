<?php
if(PHP_SAPI!=='cli')exit(1);
umask(0077);
$root='/var/www/vhosts/nuers.net/httpdocs';
require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$merchant=Illuminate\Support\Facades\DB::table('merchants')->where('business_name','Golden Bay Fresh Seafoods Restaurant Inc.')->sole();
$row=Illuminate\Support\Facades\DB::table('transaction_receipts')->where('merchant_id',$merchant->id)->where('receipt_number','GB-POS-261027-KAWLM')->sole();
$document=app(App\Services\ElectronicDocumentView::class)->make('receipt',$row);
$pdf=app(App\Services\DocumentPdf::class)->render($document);
$path=$root.'/storage/app/private/nuers-receipt-footer-qa-20261029.pdf';
if(file_exists($path))throw new RuntimeException('QA path already exists');
file_put_contents($path,$pdf);chmod($path,0600);
echo json_encode(['pdf'=>str_starts_with($pdf,'%PDF-'),'bytes'=>strlen($pdf),'path'=>$path,'sha256'=>hash('sha256',$pdf)]).PHP_EOL;
