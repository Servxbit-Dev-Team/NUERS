<?php
// Synthetic local database only. Invoked by WalletConcurrencyTest, never by web routes.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = getenv('NUERS_TEST_MYSQL_DATABASE');
if (!$app->environment('testing') || !preg_match('/^nuers_vapt_test_[a-f0-9]+$/D', (string)$db)
    || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== $db
    || config('database.connections.mysql.unix_socket') !== base_path('.local/vapt-mariadb-2026-11-14/mysql.sock')) {
    throw new RuntimeException('Refusing a non-isolated database.');
}
config(['notifications.dispatch_enabled'=>false, 'mail.default'=>'array', 'queue.default'=>'database']);
Illuminate\Support\Facades\Http::preventStrayRequests();
[$script, $action, $merchant, $event, $barrier] = $argv;
$deadline = microtime(true) + 15;
while (!is_file($barrier) && microtime(true) < $deadline) { usleep(10000); }
if (!is_file($barrier)) { throw new RuntimeException('Concurrency barrier timed out.'); }
try {
    $entry = Illuminate\Support\Facades\DB::transaction(function () use ($action, $merchant, $event) {
        $wallet=app(App\Services\MerchantWallet::class);
        return match ($action) {
            'withdraw' => $wallet->withdraw($merchant, 700, $event, 'synthetic-concurrency'),
            'credit' => $wallet->credit($merchant, 1000, $event, 'Synthetic only', 'synthetic-concurrency'),
            default => throw new RuntimeException('Unsupported test action'),
        };
    }, 5);
    echo json_encode(['status'=>200,'entry_id'=>$entry->id]);
} catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
    echo json_encode(['status'=>$e->getStatusCode()]);
}
