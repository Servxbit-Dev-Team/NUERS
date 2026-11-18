<?php
// Synthetic local database only. Invoked by RegistrationConcurrencyTest, never by web routes.
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
[$script, $challenge, $code, $barrier] = $argv;
$deadline = microtime(true) + 15;
while (!is_file($barrier) && microtime(true) < $deadline) { usleep(10000); }
if (!is_file($barrier)) { throw new RuntimeException('Concurrency barrier timed out.'); }
$request = Illuminate\Http\Request::create('/api/registration/verify-code', 'POST', ['challenge_id' => $challenge, 'code' => $code], [], [], ['HTTP_ACCEPT' => 'application/json']);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$body = json_decode($response->getContent(), true);
echo json_encode(['status'=>$response->getStatusCode(), 'code'=>$body['code'] ?? null, 'proof_hash'=>isset($body['verification_token']) ? hash('sha256', $body['verification_token']) : null]);
$kernel->terminate($request, $response);
