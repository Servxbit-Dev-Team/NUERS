<?php
require __DIR__.'/preview-bootstrap.php';
use Illuminate\Support\Facades\DB;
if(config('database.connections.sqlite.database')!==__DIR__.'/preview.sqlite'||config('bir_eis.outbound_enabled'))throw new RuntimeException('Isolated fixture only');
$p=DB::table('bir_eis_profiles')->where('name','LAB · BIR certification')->first();
if(!$p)throw new RuntimeException('Missing synthetic UI profile');
DB::table('merchants')->where('id',$p->merchant_id)->update(['address'=>'1 Synthetic Test Street, Manila']);
if(!DB::table('bir_eis_profiles')->where('name','LAB · Production setup (disabled)')->exists()){
 $q=(array)$p;$q['id']=(string)Illuminate\Support\Str::uuid();$q['environment']='production';$q['name']='LAB · Production setup (disabled)';$q['application_id']='LAB-PRODUCTION-ONLY';$q['enabled']=false;DB::table('bir_eis_profiles')->insert($q);
}
echo "Isolated preview fixtures prepared; outbound disabled.\n";
