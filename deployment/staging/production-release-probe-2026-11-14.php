<?php
// Synthetic smoke checks only; never creates customers, documents or transactions.
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('production') || base_path()!=='/srv/nuers/releases/production-20261114-vapt1') throw new RuntimeException('Release guard');
$checks=[
 'laravel_updated'=>Illuminate\Foundation\Application::VERSION==='12.71.0',
 'phpseclib_updated'=>Composer\InstalledVersions::getPrettyVersion('phpseclib/phpseclib')==='4.0.3',
 'production_timezone'=>config('app.timezone')==='UTC',
 'idle_30'=>config('security.privileged_idle_minutes')===30,
 'absolute_480'=>config('security.privileged_absolute_minutes')===480,
 'mfa_5'=>config('security.privileged_mfa_minutes')===5,
 'budget_100000'=>(int)config('infrastructure.budget_ceiling_php')===100000,
 'session_columns'=>Illuminate\Support\Facades\Schema::hasColumns('users',['api_last_activity_at','api_mfa_challenged_at']),
 'code_not_runtime_writable'=>!is_writable(base_path('app/Services/SessionPolicy.php')),
 'private_storage_writable'=>is_writable(storage_path('app/private')),
 'scanner_enabled'=>config('security.upload_scan.enabled')===true,
];
app(App\Services\UploadScanner::class)->version();$checks['scanner_signatures_fresh']=true;
$document=['id'=>'synthetic-release-check','source'=>'invoice','number'=>'SYNTHETIC-NOT-ISSUED','business'=>'Synthetic release validation','tin'=>'','document_type'=>'sales_invoice','record_status'=>'draft','status'=>'pending','category'=>'sales_invoice','issued_at'=>'2026-11-14T00:00:00Z','amount'=>112,'seller_address'=>'Synthetic address','buyer_name'=>'Synthetic customer','buyer_tin'=>null,'buyer_address'=>null,'reference'=>null,'payment_method'=>'cash','branch'=>'Synthetic','vat_registered'=>true,'items'=>[['description'=>'Synthetic item','quantity'=>1,'unit_price'=>100,'amount'=>100]],'subtotal'=>100,'discount'=>0,'vatable'=>100,'vat'=>12,'withholding'=>0,'other_charges'=>0,'permit_number'=>null,'accreditation_number'=>null,'verification_url'=>null,'authority_status'=>'not_verified','currency'=>'PHP','copy_label'=>'TEST — NOT AN ISSUED INVOICE'];
$pdf=app(App\Services\DocumentPdf::class)->render($document);
$checks['synthetic_invoice_pdf']=str_starts_with($pdf,'%PDF-')&&strlen($pdf)>1000;
unset($pdf);
echo json_encode($checks,JSON_THROW_ON_ERROR).PHP_EOL;
if(in_array(false,$checks,true))exit(1);
