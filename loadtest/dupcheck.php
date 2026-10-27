<?php

/*
 * Times the duplicate-document-number check used on every Live acceptance
 * (IntegrationTransactionController::assertNumberAvailable) and the readiness identity count
 * (EisDocumentReadiness::inspect) against the current table size,
 * and compares it with a direct indexed lookup on the receipts table.
 *
 *   php loadtest/dupcheck.php [repeats=5]
 */

use App\Services\IssuedDocumentLedger;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfg = config('database.connections.'.config('database.default'));
if (getenv('NUERS_LOADTEST') !== '1' || ! in_array((string) ($cfg['host'] ?? ''), ['127.0.0.1', 'localhost'], true) || ! str_contains((string) ($cfg['database'] ?? ''), 'load')) {
    fwrite(STDERR, "Refusing to run against a non-load-test database.\n");
    exit(1);
}

$repeats = (int) ($argv[1] ?? 5);
$merchant = (string) DB::table('merchants')->value('id');
$rows = DB::table('transaction_receipts')->count();

$time = function (callable $f) use ($repeats) {
    $f(); // warm
    $t = microtime(true);
    for ($i = 0; $i < $repeats; $i++) { $f(); }

    return round((microtime(true) - $t) * 1000 / $repeats, 2);
};

$ledger = $time(fn () => DB::query()->fromSub(app(IssuedDocumentLedger::class)->normalizedCandidates(), 'source_identity')->where('merchant_id', $merchant)->where('seller_branch_key', '00000')->where('document_family', 'invoice')->where('document_number_key', 'LT-DOES-NOT-EXIST')->exists());
// Same shape as EisDocumentReadiness::inspect(): filtered on a computed seller key, so no index can be used.
$identity = $time(fn () => DB::query()->fromSub(app(IssuedDocumentLedger::class)->normalizedCandidates(), 'identity_documents')->where('seller_tin_key', '999999999')->where('seller_branch_key', '00000')->where('document_number_key', 'LT-DOES-NOT-EXIST')->where('document_family', 'invoice')->count());
// The narrowed form now used on the acceptance path: the number is looked up through its indexed key first.
$narrowed = $time(fn () => DB::query()->fromSub(app(IssuedDocumentLedger::class)->normalizedCandidates('LT-DOES-NOT-EXIST'), 'source_identity')->where('merchant_id', $merchant)->where('seller_branch_key', '00000')->where('document_family', 'invoice')->where('document_number_key', 'LT-DOES-NOT-EXIST')->exists());
$direct = $time(fn () => DB::table('transaction_receipts')->where('merchant_id', $merchant)->where('receipt_number', 'LT-DOES-NOT-EXIST')->exists());

echo json_encode(['receipts_in_table' => $rows, 'ledger_check_full_ms' => $ledger, 'ledger_check_narrowed_ms' => $narrowed, 'readiness_identity_count_ms' => $identity, 'direct_indexed_lookup_ms' => $direct], JSON_PRETTY_PRINT)."\n";
