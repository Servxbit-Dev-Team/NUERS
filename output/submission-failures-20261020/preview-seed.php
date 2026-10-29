<?php
umask(0077);$db=__DIR__.'/preview.sqlite';if(file_exists($db))throw new RuntimeException('Preview exists');touch($db);$app=require __DIR__.'/preview-bootstrap.php';
use Illuminate\Support\Facades\{DB,Artisan,Hash};use Illuminate\Support\Str;
Artisan::call('migrate:fresh',['--force'=>true]);
$u=App\Models\User::factory()->create(['email'=>'preview-admin@example.test','password'=>Hash::make('PreviewOnly-2026!'),'email_verified_at'=>now()]);
DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'full_name'=>'Preview Admin','role'=>'super_admin']);
DB::table('merchants')->insert(['id'=>'synthetic-preview','business_name'=>'SYNTHETIC • Demo Merchant','tin'=>'123456789000','status'=>'active']);
foreach([422,402,429,409,500] as$i=>$status) DB::table('merchant_api_request_logs')->insert(['id'=>(string)Str::uuid(),'merchant_id'=>'synthetic-preview','endpoint'=>'/api/integrations/transactions','method'=>'POST','status_code'=>$status,'environment'=>'live','source_system'=>'demo-erp','source_event_id'=>'demo-event-'.$i,'request_reference'=>'DEMO-ORDER-'.($i+1),'outcome'=>$status===500?'unknown':'rejected','error_message'=>[422=>'The gross amount does not match the item totals.',402=>'Insufficient wallet balance. Top up before retrying.',429=>'This network has reached the request limit. Please wait before trying again.',409=>'Source identity was reused with different content.',500=>'NUERS could not confirm this request. Keep the original event pending and retry unchanged.'][$status],'created_at'=>now()->subMinutes($i),'updated_at'=>now()]);
echo 'Isolated preview ready'.PHP_EOL;
