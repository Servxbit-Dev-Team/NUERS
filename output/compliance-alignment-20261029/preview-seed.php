<?php
require __DIR__.'/preview-bootstrap.php';
use Illuminate\Support\Facades\{DB,Hash};use Illuminate\Support\Str;
if(config('database.connections.sqlite.database')!==__DIR__.'/preview.sqlite')throw new RuntimeException('Isolated preview only');
foreach(glob(database_path('migrations/*.php')) as $f)(require $f)->up();
$admin=App\Models\User::factory()->create(['name'=>'NUERS Lab Administrator','email'=>'compliance-admin@example.test','password'=>Hash::make('Nuers-Lab-Only-2026!'),'email_verified_at'=>now()]);
$owner=App\Models\User::factory()->create(['name'=>'Synthetic Business Owner','email'=>'compliance-owner@example.test','password'=>Hash::make('Nuers-Lab-Only-2026!'),'email_verified_at'=>now()]);
foreach([[$admin,'super_admin'],[$owner,'merchant']] as [$u,$role]) DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'full_name'=>$u->name,'role'=>$role]);
$id=(string)Str::uuid();DB::table('merchants')->insert(['id'=>$id,'merchant_account_id'=>$owner->id,'business_name'=>'LAB · Synthetic Merchant','tin'=>'000000000000','status'=>'active','address'=>'Synthetic laboratory address','vat_registered'=>true]);
$o=app(App\Services\ComplianceOperations::class)->create($id,$owner->id,'outage',['request_id'=>(string)Str::uuid(),'title'=>'LAB · Internet outage recovery drill','started_at'=>now()->subHours(2)->toIso8601String(),'evidence_reference'=>'SYNTHETIC-DRILL-ONLY']);
app(App\Services\ComplianceOperations::class)->create($id,$owner->id,'manual_invoice',['request_id'=>(string)Str::uuid(),'outage_id'=>$o['id'],'manual_number'=>'LAB-MANUAL-001','branch_code'=>'00000','issued_at'=>now()->subHour()->toIso8601String(),'amount'=>1120,'buyer_name'=>'Synthetic Buyer','authority_reference'=>'LAB-ONLY · Not an actual authority','evidence_reference'=>'Synthetic manual invoice sample']);
echo "Synthetic preview prepared; outbound disabled.\n";
