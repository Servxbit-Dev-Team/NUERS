<?php
// Synthetic in-memory HTTP validation. Never uses production data or sends notifications.
try {
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (base_path()!=='/srv/nuers/releases/production-20261115-input1') throw new RuntimeException('Candidate guard');
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','database.connections.sqlite.url'=>null,'cache.default'=>'array','session.driver'=>'array','queue.default'=>'database','mail.default'=>'array','notifications.dispatch_enabled'=>false,'infrastructure.execution_enabled'=>false,'security.enforce_privileged_mfa'=>true,'hashing.bcrypt.rounds'=>4,'app.debug'=>false]);
Illuminate\Support\Facades\DB::purge('mysql');
Illuminate\Support\Facades\DB::purge('sqlite');
if (Illuminate\Support\Facades\DB::connection()->getDatabaseName()!==':memory:' || Illuminate\Support\Facades\DB::getDriverName()!=='sqlite') throw new RuntimeException('Database guard');
Illuminate\Support\Facades\Http::preventStrayRequests();
foreach (glob(database_path('migrations/*.php')) as $file) (require $file)->up();
$checks=[];
$actor=function(string $role) {
 $token=Illuminate\Support\Str::random(64);
 $u=App\Models\User::create(['name'=>'Synthetic native probe','email'=>Illuminate\Support\Str::uuid().'@example.test','password'=>'Synthetic-Native-Only-2026','api_token'=>$token]);
 $u->forceFill(['api_device_hash'=>hash('sha256','synthetic-native-device')])->save();
 Illuminate\Support\Facades\DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'role'=>$role]);
 return [$u,$token];
};
$send=function(string $path,?string $token,int $expected,string $label) use ($app,&$checks) {
 $r=Illuminate\Http\Request::create($path,'GET',server:['HTTP_ACCEPT'=>'application/json','HTTP_X_NUERS_DEVICE'=>'synthetic-native-device','HTTP_AUTHORIZATION'=>$token?'Bearer '.$token:'']);
 $response=$app->make(Illuminate\Contracts\Http\Kernel::class)->handle($r);
 $checks[$label]=$response->getStatusCode()===$expected;
 if (!$checks[$label]) throw new RuntimeException($label.' unexpected status '.$response->getStatusCode());
 return json_decode($response->getContent(),true);
};
[$client,$token]=$actor('client');
$actor('client');
$send('/api/tables/profiles?access_token='.urlencode($token),null,401,'query_token_rejected');
foreach (["' OR '1'='1",'1) OR 1=1--','${7*7}','{{7*7}}','../../etc/passwd'] as $i=>$value) {
 $body=$send('/api/tables/profiles?'.http_build_query(['eq'=>json_encode(['id'=>$value])]),$token,200,'literal_filter_'.$i);
 if (($body['data']??null)!==[]) throw new RuntimeException('Scope widened');
}
foreach ([['eq'=>json_encode(['id'=>['nested']])],['eq'=>['id'=>['nested']]],['in'=>json_encode(['id'=>[['nested']]])]] as $i=>$query) $send('/api/tables/profiles?'.http_build_query($query),$token,422,'nested_filter_'.$i);
[$admin,$adminToken]=$actor('super_admin');
$body=$send('/api/tables/profiles?'.http_build_query(['eq'=>['id'=>(string)$admin->id]]),$adminToken,200,'own_profile_array');
if (count($body['data']??[])!==1) throw new RuntimeException('Own profile count');
$send('/api/tables/profiles?'.http_build_query(['eq'=>['id'=>['nested']]]),$adminToken,403,'nested_cannot_bypass_mfa');
$send('/api/tables/profiles?'.http_build_query(['eq'=>['id'=>'foreign']]),$adminToken,403,'foreign_cannot_bypass_mfa');
$send('/api/tables/merchants',$adminToken,403,'mfa_still_required');
if (Illuminate\Support\Facades\DB::table('users')->count()!==3) throw new RuntimeException('Synthetic count');
echo json_encode(['native_input_probe'=>'passed','php'=>PHP_VERSION,'database'=>':memory:','checks'=>count($checks),'results'=>$checks]).PHP_EOL;

} catch (Throwable $e) { fwrite(STDERR, 'NATIVE_PROBE_FAILED: '.$e->getMessage().PHP_EOL); exit(1); }
