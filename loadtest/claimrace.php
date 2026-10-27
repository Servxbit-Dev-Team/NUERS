<?php

/*
 * Several processes claim from one backlog of merchant webhook deliveries at once. Every delivery must be leased
 * exactly once, with its own lease token, and no worker may stop while due deliveries remain.
 *
 *   php loadtest/claimrace.php <workers=8> <rows=2000> [legacy]
 *
 * "legacy" runs the claim query as it was before 2026-10-27 for comparison. Seeds throwaway delivery rows (removed
 * afterwards), starts the workers as separate PHP processes and reads the results back. Same safety guard as seed.php.
 * Exit code 0 only if every check passes.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfg = config('database.connections.'.config('database.default'));
if (getenv('NUERS_LOADTEST') !== '1' || ! in_array((string) ($cfg['host'] ?? ''), ['127.0.0.1', 'localhost'], true) || ! str_contains((string) ($cfg['database'] ?? ''), 'load')) {
    fwrite(STDERR, "Refusing to run against a non-load-test database.\n");
    exit(1);
}

$workers = max(1, (int) ($argv[1] ?? 8));
$rows = max(1, (int) ($argv[2] ?? 2000));
$mode = ($argv[3] ?? '') === 'legacy' ? 'legacy' : 'current';
$marker = 'claimrace-'.Str::lower(Str::random(8));
$dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.$marker;
mkdir($dir);

$now = now();
foreach (array_chunk(range(1, $rows), 500) as $chunk) {
    DB::table('merchant_webhook_deliveries')->insert(array_map(fn ($i) => [
        'id' => (string) Str::uuid(), 'endpoint_id' => (string) Str::uuid(), 'merchant_id' => 'claimrace', 'environment' => 'live',
        'event_key' => hash('sha256', $marker.$i), 'event_type' => 'invoice.issued', 'subject_id' => $marker, 'encrypted_body' => 'x',
        'body_hash' => str_repeat('a', 64), 'secret_version' => 1, 'state' => 'pending', 'available_at' => $now->copy()->subMinute(),
        'retry_deadline_at' => $now->copy()->addDay(), 'created_at' => $now, 'updated_at' => $now,
    ], $chunk));
}

$t0 = microtime(true);
$procs = [];
$pipes = [];
for ($w = 0; $w < $workers; $w++) {
    $cmd = [PHP_BINARY, __DIR__.'/claimworker.php', $dir.DIRECTORY_SEPARATOR."w$w.txt", $mode];
    $procs[$w] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$w], null, null);
}
$perWorker = [];
$problems = [];
foreach ($procs as $w => $p) {
    $raw = trim(stream_get_contents($pipes[$w][1]));
    $err = trim(stream_get_contents($pipes[$w][2]));
    $code = proc_close($p);
    $perWorker[$w] = json_decode($raw, true) ?? ['claimed' => null];
    if ($perWorker[$w]['claimed'] === null || $err !== '' || $code !== 0) {
        $problems[] = "worker $w exit=$code stdout=[".substr(preg_replace('/\s+/', ' ', $raw), 0, 420).'] stderr=['.substr(preg_replace('/\s+/', ' ', $err), 0, 300).']';
    }
}
$elapsed = round(microtime(true) - $t0, 2);

$ids = [];
$tokens = [];
foreach (glob($dir.DIRECTORY_SEPARATOR.'w*.txt') as $f) {
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$id, $token] = explode(' ', $line);
        $ids[] = $id;
        $tokens[] = $token;
    }
}
$mine = DB::table('merchant_webhook_deliveries')->where('subject_id', $marker);
$seeded = (clone $mine)->pluck('id')->all();
$delivered = (clone $mine)->where('state', 'delivered')->count();
// A worker that finds the last rows locked by another worker may stop with them still "due" for a moment, so judge the final state.
$dueAtEnd = (clone $mine)->whereIn('state', ['pending', 'retry', 'sending'])->count();

$checks = [
    'every seeded delivery was leased' => count(array_intersect($ids, $seeded)) === $rows,
    'no delivery was leased twice' => count($ids) === count(array_unique($ids)),
    'every lease has its own token' => count($tokens) === count(array_unique($tokens)),
    'nothing is left due once all workers have stopped' => $dueAtEnd === 0,
    'every worker ran to completion without errors' => $problems === [],
    'no other delivery was touched' => count(array_diff($ids, $seeded)) === 0,
    'every delivery ended delivered' => $delivered === $rows,
];
echo json_encode([
    'mode' => $mode, 'workers' => $workers, 'rows' => $rows, 'seconds' => $elapsed, 'claims_per_second' => round($rows / max($elapsed, 0.001)),
    'claimed_per_worker' => array_map(fn ($w) => $w['claimed'], $perWorker),
], JSON_UNESCAPED_SLASHES)."\n";
foreach ($problems as $line) {
    echo "  ! $line\n";
}
$ok = true;
foreach ($checks as $name => $pass) {
    echo ($pass ? 'PASS  ' : 'FAIL  ').$name."\n";
    $ok = $ok && $pass;
}

DB::table('merchant_webhook_deliveries')->where('subject_id', $marker)->delete();
foreach (glob($dir.DIRECTORY_SEPARATOR.'*') as $f) {
    unlink($f);
}
rmdir($dir);
exit($ok ? 0 : 1);
