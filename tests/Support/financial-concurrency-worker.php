<?php

// Local synthetic harness only: no production connection or external delivery.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.vapt-absent');
$app->make(Kernel::class)->bootstrap();
use App\Services\MerchantWallet;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

$db = getenv('NUERS_TEST_MYSQL_DATABASE');
if (! $app->environment('testing') || ! preg_match('/^nuers_vapt_test_[a-f0-9]+$/D', (string) $db) || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== $db || config('database.connections.mysql.unix_socket') !== base_path('.local/vapt-mariadb-2026-11-14/mysql.sock')) {
    throw new RuntimeException('Nonisolated database refused');
}
config(['cache.default' => 'array', 'notifications.dispatch_enabled' => false, 'infrastructure.execution_enabled' => false, 'infrastructure.enabled' => false, 'app.debug' => false, 'security.enforce_privileged_mfa' => true, 'bir_compliance.configuration_reference' => 'synthetic-race-build']);
Http::preventStrayRequests();
Mail::fake();
Queue::fake();
$job = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$dir = $argv[1];
$slot = $argv[2];
$wait = function ($file) {
    $limit = microtime(true) + 45;
    while (! is_file($file) && microtime(true) < $limit) {
        usleep(10000);
    }if (! is_file($file)) {
        throw new RuntimeException('Barrier deadline');
    }
};
if (! empty($job['pause_read'])) {
    $paused = false;
    DB::listen(function ($q) use (&$paused, $dir, $slot, $wait) {
        if (! $paused && DB::transactionLevel() === 0 && preg_match('/^select \* from [`"]merchants[`"]/', $q->sql)) {
            $paused = true;
            touch($dir.'/paused-'.$slot);
            $wait($dir.'/resume');
        }
    });
}
touch($dir.'/ready-'.$slot);
$wait($dir.'/go');
try {
    if (($job['action'] ?? 'http') === 'withdraw') {
        $entry = DB::transaction(fn () => app(MerchantWallet::class)->withdraw($job['merchant'], 700, $job['event'], 'synthetic-race'), 5);
        $status = 200;
        $body = ['entry_id' => $entry->id];
    } else {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($job['headers'] as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $r = Request::create($job['url'], $job['method'] ?? 'POST', server: $server, content: json_encode($job['body']));
        $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($r);
        $status = $response->getStatusCode();
        $body = json_decode($response->getContent(), true) ?? [];
    }
    echo json_encode(['status' => $status, 'body' => $body], JSON_THROW_ON_ERROR);
} catch (HttpException $e) {
    echo json_encode(['status' => $e->getStatusCode(), 'body' => ['message' => $e->getMessage()]]);
}
