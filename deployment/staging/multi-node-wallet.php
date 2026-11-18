<?php
/** Disposable, synthetic two-host ledger race. Never deploy as a public endpoint. */
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Services\MerchantWallet;
if (!$app->environment('staging') || config('database.connections.mysql.database') !== 'nuers_vapt_multi'
    || config('database.connections.mysql.host') !== '10.85.0.70') throw new RuntimeException('Synthetic staging guard');
config(['notifications.dispatch_enabled'=>false,'mail.default'=>'array']);
Illuminate\Support\Facades\Http::preventStrayRequests();
[$script,$action,$merchant] = $argv;
if (!preg_match('/^vapt-[a-f0-9]{24}$/D',$merchant)) throw new RuntimeException('Synthetic merchant guard');
$wallet=app(MerchantWallet::class);
if ($action==='init') {
    if (DB::table('merchants')->where('id',$merchant)->exists()) throw new RuntimeException('Fixture already exists');
    DB::table('merchants')->insert(['id'=>$merchant,'business_name'=>'Synthetic cross-node ledger test','status'=>'active']);
    if (($argv[3]??'')==='funded') DB::transaction(fn()=>$wallet->credit($merchant,1000,'opening','Synthetic only','staging-race'));
    echo "READY\n";exit;
}
if ($action==='audit') {echo json_encode($wallet->audit($merchant));exit;}
$at=(float)($argv[4]??0);$remaining=$at-microtime(true);
if ($remaining < -10 || $remaining > 45) throw new RuntimeException('Invalid race barrier');
while(microtime(true)<$at) usleep(10000);
try {
    $entry=DB::transaction(fn()=>match($action){
        'withdraw'=>$wallet->withdraw($merchant,700,$argv[3],'synthetic-two-node'),
        'credit'=>$wallet->credit($merchant,1000,$argv[3],'Synthetic only','synthetic-two-node'),
        default=>throw new RuntimeException('Unsupported action'),
    },5);
    echo json_encode(['status'=>200,'entry_id'=>$entry->id]);
} catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {echo json_encode(['status'=>$e->getStatusCode()]);}
