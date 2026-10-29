<?php
require __DIR__.'/preview-bootstrap.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
if(config('database.connections.sqlite.database')!==__DIR__.'/preview.sqlite')throw new RuntimeException('Wrong test target');
$id=900001;
DB::table('users')->updateOrInsert(['id'=>$id],['name'=>'NUERS Synthetic QA','email'=>'all-pages-admin@example.test','password'=>Hash::make('NuersLabOnly-2026!X'),'email_verified_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
DB::table('profiles')->updateOrInsert(['id'=>$id],['role'=>'super_admin','full_name'=>'NUERS Synthetic QA','email'=>'all-pages-admin@example.test']);
echo 'Local synthetic login ready';
$id=900002;
DB::table('users')->updateOrInsert(['id'=>$id],['name'=>'NUERS Synthetic Merchant','email'=>'all-pages-merchant@example.test','password'=>Hash::make('NuersLabOnly-2026!X'),'email_verified_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
DB::table('profiles')->updateOrInsert(['id'=>$id],['role'=>'merchant','full_name'=>'NUERS Synthetic Merchant','email'=>'all-pages-merchant@example.test']);
$m=DB::table('merchants')->orderBy('id')->first();
DB::table('merchants')->where('id',$m->id)->update(['merchant_account_id'=>$id,'status'=>'active']);
echo ' and merchant ready';
