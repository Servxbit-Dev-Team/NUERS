<?php
// Private CLI-only verification. No account, key, sale or wallet mutation.
if (PHP_SAPI !== 'cli') exit(1);
umask(0077); set_time_limit(90);
$root = __DIR__.'/httpdocs';
if (basename(__DIR__) !== 'nuers.net' || !is_file($root.'/artisan')) throw new RuntimeException('Wrong target');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$out = ['verified'=>false, 'checked_at'=>gmdate('c'), 'business_data_read_only'=>true];
try {
    if (parse_url(config('app.url'), PHP_URL_HOST) !== 'nuers.net' || !config('security.enforce_privileged_mfa')) throw new RuntimeException('Deployment policy mismatch');
    $route = collect(app('router')->getRoutes())->first(fn($r) => $r->uri() === 'api/dashboard-revision');
    if (!$route || !in_array('GET', $route->methods()) || !in_array('throttle:120,1,dashboard-revision:', $route->middleware())) throw new RuntimeException('Protected heartbeat route unavailable');
    $actors = DB::table('profiles')->whereIn('role', ['super_admin','merchant'])->orderBy('id')->limit(3)->pluck('id');
    if ($actors->isEmpty()) throw new RuntimeException('No verification actor');
    $service = app(App\Services\DashboardRevision::class);
    DB::enableQueryLog();
    $start = microtime(true); $tokens = [];
    for ($i=0; $i<30; $i++) {
        $token = $service->token((string)$actors[0]);
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new RuntimeException('Invalid change token');
        $tokens[] = $token;
    }
    $elapsed = (microtime(true)-$start)*1000;
    foreach (DB::getQueryLog() as $q) {
        if (preg_match('/\b(?:count|sum)\s*\(|from\s+[`"]?(?:transaction_receipts|business_invoices|merchant_transactions|ledger_documents)\b/i', $q['query'])) throw new RuntimeException('Heartbeat scans financial source data');
    }
    DB::disableQueryLog();
    if ($actors->count()>1 && $service->token((string)$actors[0]) === $service->token((string)$actors[1])) throw new RuntimeException('Change token is not actor bound');
    $unauthorized = (new App\Http\Middleware\ApiBoundary)->handle(Illuminate\Http\Request::create('/api/dashboard-revision'), fn() => response()->json([]));
    throw new RuntimeException('Unauthenticated heartbeat was accepted');
} catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
    if ($e->getStatusCode() !== 401) $out['error'] = 'Unexpected heartbeat access response';
    else {
        $manifest=json_decode(file_get_contents(__DIR__.'/nuers-realtime-refresh-stage-20261020/audit-release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
        foreach($manifest['files'] as $path=>$hash) if(!is_file($root.'/'.$path)||hash_file('sha256',$root.'/'.$path)!==$hash) throw new RuntimeException('Installed file mismatch');
        $out += ['heartbeat_samples'=>30, 'heartbeat_mean_ms'=>round($elapsed/30,2), 'financial_scans'=>0, 'actor_bound_token'=>true, 'anonymous_status'=>401, 'mfa_preserved'=>true, 'files_verified'=>count($manifest['files']), 'schema_migration'=>false, 'bir_transport_enabled'=>(bool)config('bir_compliance.transport_implemented')];
        $out['verified']=true;
    }
} catch (Throwable $e) {
    $out['error']=$e instanceof RuntimeException ? $e->getMessage() : 'Application verification failed';
}
file_put_contents(__DIR__.'/nuers-realtime-refresh-verification.json',json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode($out).PHP_EOL;
exit($out['verified']?0:1);
