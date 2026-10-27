<?php

/*
 * In-process profile of the Live acceptance path: queries, DB time and wall time per request.
 *
 *   php loadtest/profile.php <keys.json> [requests=30]
 *
 * Same safety guard as seed.php.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// The app is not bootstrapped until the first request, so read the connection settings from the environment.
if (getenv('NUERS_LOADTEST') !== '1' || ! in_array((string) getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true) || ! str_contains((string) getenv('DB_DATABASE'), 'load')) {
    fwrite(STDERR, "Refusing to run against a non-load-test database.\n");
    exit(1);
}

$kernel->bootstrap();
$keys = json_decode(file_get_contents($argv[1]), true);
$n = (int) ($argv[2] ?? 30);
$run = dechex(time());
$queries = [];
DB::listen(function ($q) use (&$queries) {
    $caller = null;
    if (str_contains($q->sql, 'information_schema')) {
        // First frame inside app/ (skipping vendor) identifies which code asked for the schema.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $f) {
            if (isset($f['file']) && str_contains(str_replace('\\', '/', $f['file']), '/app/')) { $caller = basename($f['file']).':'.($f['line'] ?? '?'); break; }
        }
    }
    $queries[] = ['sql' => $q->sql, 'ms' => $q->time, 'bindings' => $q->bindings, 'caller' => $caller, 'at' => microtime(true)];
    // Start of the lock being studied: the first SELECT ... FOR UPDATE on the table named by PROFILE_LOCK (default eis_audit_head,
    // the global audit head; use `merchants` for the per-merchant lock). Time it was issued, not when it returned.
    if (str_contains($q->sql, getenv('PROFILE_LOCK') ?: 'eis_audit_head') && str_contains($q->sql, 'for update') && $GLOBALS['lockStart'] === null) {
        $GLOBALS['lockStart'] = microtime(true) - $q->time / 1000;
    }
});
$GLOBALS['lockStart'] = null;
$GLOBALS['commitAt'] = null;
Illuminate\Support\Facades\Event::listen(Illuminate\Database\Events\TransactionCommitted::class, function ($e) {
    // First outermost commit after the audit-head lock was taken = the acceptance transaction. Later outermost commits
    // (the API request log, usage counters) are separate transactions and must not stretch the measured hold.
    if ($e->connection->transactionLevel() === 0 && $GLOBALS['lockStart'] !== null && $GLOBALS['commitAt'] === null) { $GLOBALS['commitAt'] = microtime(true); }
});
$holds = [];

$wall = [];
$counts = [];
$dbms = [];
for ($i = 0; $i < $n + 3; $i++) {
    $k = $keys[$i % count($keys)];
    $ref = "$run-p$i";
    $body = json_encode(['schema_version' => 'NUERS-ingestion-1', 'record_mode' => 'source_capture', 'source_profile_id' => $k['profile_id'], 'source_system' => 'loadtest-pos', 'source_event_id' => "lt-$ref", 'external_reference' => "lt-inv-$ref", 'event_type' => 'invoice.finalized', 'occurred_at' => gmdate('Y-m-d\TH:i:s').'+00:00', 'branch_code' => '00000', 'receipt_number' => "LT-$ref", 'document_type' => 'vat_invoice', 'payment_method' => 'cash', 'gross_amount' => '560.00', 'tax_type' => 'vatable', 'vat_inclusive' => true, 'items' => [['description' => 'Synthetic load item', 'quantity' => 1, 'unit_price' => '560.00', 'tax_type' => 'vatable']]]);
    $req = Request::create('/api/integrations/transactions', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_NUERS_API_KEY' => $k['key'], 'REMOTE_ADDR' => '10.9.'.($i % 250).'.'.($i % 200)], $body);
    $queries = [];
    $GLOBALS['lockStart'] = null;
    $GLOBALS['commitAt'] = null;
    $t = microtime(true);
    $res = $kernel->handle($req);
    $ms = (microtime(true) - $t) * 1000;
    if ($i < 3) { continue; } // warm-up
    if ($res->getStatusCode() !== 201) { fwrite(STDERR, 'Unexpected '.$res->getStatusCode().': '.substr($res->getContent(), 0, 300)."\n"); }
    if ($GLOBALS['lockStart'] !== null && $GLOBALS['commitAt'] !== null) { $holds[] = ($GLOBALS['commitAt'] - $GLOBALS['lockStart']) * 1000; }
    $wall[] = $ms;
    $counts[] = count($queries);
    $dbms[] = array_sum(array_column($queries, 'ms'));
    $last = $queries;
}

$avg = fn (array $a) => round(array_sum($a) / max(1, count($a)), 1);
sort($holds);
$med = fn (array $a) => $a ? round($a[intdiv(count($a), 2)], 1) : null;
sort($wall);
echo json_encode(['requests' => $n, 'avg_wall_ms' => $avg($wall), 'p50_wall_ms' => round($wall[intdiv(count($wall), 2)], 1), 'avg_queries' => $avg($counts), 'avg_db_ms' => $avg($dbms), 'audit_head_lock_hold_ms' => ['median' => $med($holds), 'mean' => $avg($holds), 'p90' => $holds ? round($holds[(int) floor(count($holds) * 0.9)], 1) : null, 'samples' => count($holds)]], JSON_PRETTY_PRINT)."\n";

$byShape = [];
foreach ($last as $q) {
    $shape = preg_replace('/\s+/', ' ', substr($q['sql'], 0, 70));
    $byShape[$shape] = ($byShape[$shape] ?? ['n' => 0, 'ms' => 0]);
    $byShape[$shape]['n']++;
    $byShape[$shape]['ms'] += $q['ms'];
}
uasort($byShape, fn ($a, $b) => $b['ms'] <=> $a['ms']);
echo "Slowest query shapes in the last request:\n";
foreach (array_slice($byShape, 0, 12, true) as $s => $v) {
    printf("  %6.2f ms  x%-3d %s\n", $v['ms'], $v['n'], $s);
}

// Timeline of the last request from the moment the audit-head lock was requested to the outermost commit.
if ($GLOBALS['lockStart'] !== null) {
    echo "Timeline inside the audit-head lock, last request (offset from lock request, query time, statement):
";
    $prevEnd = $GLOBALS['lockStart'];
    $inWindowDb = 0.0;
    foreach ($last as $q) {
        $startedAt = $q['at'] - $q['ms'] / 1000;
        if ($startedAt + 0.0005 < $GLOBALS['lockStart']) { continue; }
        if ($GLOBALS['commitAt'] !== null && $startedAt > $GLOBALS['commitAt']) { break; }
        // "php" is the time between the end of the previous statement and the start of this one (application code, commit, network).
        printf("  +%6.1f ms  db %6.2f ms  php %6.2f ms  %s
", ($startedAt - $GLOBALS['lockStart']) * 1000, $q['ms'], max(0, ($startedAt - $prevEnd) * 1000), preg_replace('/\s+/', ' ', substr($q['sql'], 0, 80)));
        $prevEnd = $q['at'];
        $inWindowDb += $q['ms'];
    }
    if ($GLOBALS['commitAt'] !== null) {
        $window = ($GLOBALS['commitAt'] - $GLOBALS['lockStart']) * 1000;
        printf("  +%6.1f ms  outermost COMMIT returned
  window %.1f ms = statements %.1f ms + everything else (application code, commit flush) %.1f ms
", $window, $window, $inWindowDb, $window - $inWindowDb);
    }
}
$callers = [];
foreach ($last as $q) { if ($q['caller']) { $callers[$q['caller']] = ($callers[$q['caller']] ?? ['n' => 0, 'ms' => 0]); $callers[$q['caller']]['n']++; $callers[$q['caller']]['ms'] += $q['ms']; } }
uasort($callers, fn ($a, $b) => $b['n'] <=> $a['n']);
echo "information_schema lookups in the last request, by caller (file:line):
";
foreach ($callers as $c => $v) { printf("  x%-3d %6.2f ms  %s
", $v['n'], $v['ms'], $c); }

// Re-run the slowest query on its own to separate query cost from lock waits and cold caches.
usort($last, fn ($a, $b) => $b['ms'] <=> $a['ms']);
$slow = $last[0];
$times = [];
for ($i = 0; $i < 3; $i++) { $t = microtime(true); DB::select($slow['sql'], $slow['bindings']); $times[] = round((microtime(true) - $t) * 1000, 1); }
echo "Slowest query re-run alone (ms): ".implode(', ', $times)." (in-request: {$slow['ms']})
";
echo "Bindings: ".json_encode($slow['bindings'])."
";
$plan = DB::select('EXPLAIN ANALYZE '.$slow['sql'], $slow['bindings']);
echo substr(json_encode(array_values((array) $plan[0])[0], JSON_UNESCAPED_SLASHES), 0, 1800)."
";
