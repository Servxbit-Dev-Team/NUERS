<?php
/** Synthetic two-node harness only. Never part of a production release. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app=require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use App\Models\User;
if (!in_array(app()->environment(), ['staging','testing'],true) || config('database.connections.mysql.database') !== 'nuers_vapt_multi') throw new RuntimeException('Synthetic database guard');
$op=$argv[1] ?? '';
if ($op==='seed') {
    if (User::count()) throw new RuntimeException('Refuse nonempty fixture database');
    $fixtures=[];
    foreach (['admin','merchant_a','merchant_b','idle','absolute','stepup'] as $name) {
        $role=str_starts_with($name,'merchant')?'merchant':'super_admin';
        $password=bin2hex(random_bytes(24)).'Aa7';$secret=app(App\Services\Totp::class)->secret();
        $u=User::create(['name'=>'Synthetic '.$name,'email'=>$name.'@example.test','password'=>$password]);
        $u->forceFill(['email_verified_at'=>now(),'mfa_secret'=>Crypt::encryptString($secret)])->save();
        DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'role'=>$role]);
        if ($role==='merchant') DB::table('merchants')->insert(['id'=>$name,'merchant_account_id'=>$u->id,'business_name'=>'Synthetic '.$name,'status'=>'active']);
        $fixtures[$name]=['id'=>$u->id,'email'=>$u->email,'password'=>$password,'totp_secret'=>$secret];
    }
    // Caller redirects this to a root-only file. Never put fixtures in SSM stdout.
    echo json_encode($fixtures,JSON_THROW_ON_ERROR);
} elseif (in_array($op,['idle','absolute','stepup'],true)) {
    $u=User::where('email',$op.'@example.test')->firstOrFail();
    $values=match($op){
        'idle'=>['api_expires_at'=>now()->subMinute()],
        'absolute'=>['api_authenticated_at'=>now()->subHours(9),'api_expires_at'=>now()->addMinutes(20)],
        'stepup'=>['api_authenticated_at'=>now()->subMinutes(10),'api_mfa_verified_at'=>now()->subMinutes(6)],
    };
    $u->forceFill($values)->save();echo "Synthetic timestamp adjusted\n";
} else { throw new InvalidArgumentException('Unknown operation'); }
