<?php

/*
 * Reads back the state of one merchant after a race scenario and prints the invariants as JSON.
 *
 *   php loadtest/race-verify.php <merchant_id> [receipt_number_prefix]
 *
 * Same safety guard as seed.php. Read-only.
 */

use App\Services\BirCompliance;
use App\Services\MerchantWallet;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfg = config('database.connections.'.config('database.default'));
if (getenv('NUERS_LOADTEST') !== '1' || ! in_array((string) ($cfg['host'] ?? ''), ['127.0.0.1', 'localhost'], true) || ! str_contains((string) ($cfg['database'] ?? ''), 'load')) {
    fwrite(STDERR, "Refusing to run against a non-load-test database.\n");
    exit(1);
}

$m = (string) ($argv[1] ?? '');
$prefix = (string) ($argv[2] ?? '');
$byMerchant = fn (string $t) => DB::table($t)->where('merchant_id', $m);
$receipts = $byMerchant('transaction_receipts');
if ($prefix !== '') {
    $receipts->where('receipt_number', 'like', $prefix.'%');
}
$wallet = app(MerchantWallet::class);
$audit = $wallet->audit($m);
$fees = DB::table('merchant_wallet_entries')->where('merchant_id', $m)->where('kind', 'fee');

echo json_encode([
    'inbox' => $byMerchant('integration_inbox')->count(),
    'transactions' => $byMerchant('merchant_transactions')->count(),
    'receipts' => (clone $receipts)->count(),
    'duplicate_receipt_numbers' => DB::table('transaction_receipts')->where('merchant_id', $m)->select('document_number_key')->whereNotNull('document_number_key')->groupBy('document_number_key')->havingRaw('count(*) > 1')->get()->count(),
    'duplicate_event_hashes' => $byMerchant('integration_inbox')->select('event_hash')->groupBy('event_hash')->havingRaw('count(*) > 1')->get()->count(),
    'fee_entries' => (clone $fees)->count(),
    'fees_charged_centavos' => -1 * (int) (clone $fees)->sum('amount_centavos'),
    'wallet_available_centavos' => (int) DB::table('merchant_wallets')->where('merchant_id', $m)->value('available_centavos'),
    'wallet_min_balance_after' => DB::table('merchant_wallet_entries')->where('merchant_id', $m)->min('balance_after'),
    'wallet_chain_valid' => $audit['valid'],
    'wallet_entries' => $audit['entries'],
    'audit_chain_intact' => app(BirCompliance::class)->auditIntegrity(),
], JSON_PRETTY_PRINT)."\n";
