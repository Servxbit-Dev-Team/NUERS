<?php

/*
 * After a run: checks that every accepted event is durable and the audit hash chain is intact.
 *
 *   php loadtest/verify.php
 *
 * Same safety guard as seed.php. Read-only except for the full-chain scan.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfg = config('database.connections.'.config('database.default'));
if (getenv('NUERS_LOADTEST') !== '1' || ! in_array((string) ($cfg['host'] ?? ''), ['127.0.0.1', 'localhost'], true) || ! str_contains((string) ($cfg['database'] ?? ''), 'load')) {
    fwrite(STDERR, "Refusing to run against a non-load-test database.\n");
    exit(1);
}

$inbox = DB::table('integration_inbox')->count();
$tx = DB::table('merchant_transactions')->count();
// Rows cloned by grow.php are receipts with no inbox entry; leave them out of the consistency check.
$receipts = DB::table('transaction_receipts')->where('receipt_number', 'not like', 'GROW-%')->count();
$audit = DB::table('eis_audit_events')->where('event', 'integration.event.accepted')->count();
$dupes = DB::table('integration_inbox')->select('event_hash')->groupBy('event_hash')->havingRaw('count(*) > 1')->get()->count();
$t0 = microtime(true);
$intact = app(App\Services\BirCompliance::class)->auditIntegrity();
$scan = round(microtime(true) - $t0, 1);

echo json_encode([
    'integration_inbox' => $inbox, 'merchant_transactions' => $tx, 'transaction_receipts' => $receipts,
    'accepted_audit_events' => $audit, 'duplicate_event_hashes' => $dupes,
    'counts_consistent' => $inbox === $tx && $tx === $receipts && $receipts === $audit,
    'audit_chain_intact' => $intact, 'chain_scan_seconds' => $scan,
], JSON_PRETTY_PRINT)."\n";
