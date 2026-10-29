<?php
// Read-only, CLI-only verification; outputs aggregate timings, never credentials or customer data.
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
set_time_limit(50);
$root = __DIR__.'/httpdocs';
if (basename(__DIR__) !== 'nuers.net' || !is_file($root.'/artisan')) throw new RuntimeException('Wrong target');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$out = ['checked_at' => gmdate('c'), 'read_only' => true, 'verified' => false];
try {
    if (parse_url(config('app.url'), PHP_URL_HOST) !== 'nuers.net') throw new RuntimeException('Wrong domain');
    if (!config('security.enforce_privileged_mfa')) throw new RuntimeException('MFA policy changed');
    $version = DB::selectOne('SELECT VERSION() AS version')->version;
    if (str_contains($version, 'MariaDB')) DB::statement('SET SESSION max_statement_time=12');
    else DB::statement('SET SESSION max_execution_time=12000');
    $userId = DB::table('users')->where('email', 'goldenbayfreshseafood@gmail.com')->value('id');
    $merchant = DB::table('merchants')->where('merchant_account_id', $userId)->first();
    if (!$merchant || $merchant->status !== 'active') throw new RuntimeException('Target account unavailable');
    $queries = 0;
    DB::listen(function () use (&$queries) { $queries++; });
    $started = microtime(true);
    $today = now('Asia/Manila');
    $report = app(App\Services\MerchantSalesReport::class)->generate($merchant, [
        'from' => $today->startOfMonth()->toDateString(), 'to' => now('Asia/Manila')->toDateString(),
        'branch' => 'all', 'type' => 'all', 'sale_category' => 'all', 'search' => '', 'customer_type' => 'all', 'bir' => 'all',
    ]);
    $out['report_ms'] = round((microtime(true) - $started) * 1000);
    $out['report_queries'] = $queries;
    $out['included_documents'] = $report['count'];
    $out['excluded_documents'] = $report['excluded_count'];
    $out['report_totals_centavos'] = $report['totals'];
    if ($out['report_ms'] > 15000) throw new RuntimeException('Report exceeds time budget');
    $started = microtime(true);
    $rows = app(App\Services\IssuedDocumentLedger::class)->query((string)$merchant->id)
        ->whereIn('document_source', ['invoice', 'receipt'])->orderBy('id')->limit(600)->get();
    $financials = app(App\Services\EisDocumentReadiness::class)->financialBatch($rows, (string)$merchant->id);
    $out['batch_documents'] = $rows->count();
    $out['batch_ms'] = round((microtime(true) - $started) * 1000);
    $matches = 0;
    foreach ($rows->take(3) as $row) {
        $full = app(App\Services\EisDocumentReadiness::class)->inspect($row->document_source, $row->document_id);
        $batch = $financials[$row->document_source.':'.$row->document_id];
        if ($batch['financial_validation_passed'] !== $full['financial_validation_passed']
            || $batch['items'] !== $full['model']['items']) throw new RuntimeException('Financial parity check failed');
        $matches++;
    }
    $out['full_validation_parity_samples'] = $matches;
    $out['verified'] = true;
} catch (Throwable $e) {
    $out['error_class'] = get_class($e);
    $out['error_category'] = str_contains(strtolower($e->getMessage()), 'time') ? 'performance_budget' : 'verification_failed';
}
file_put_contents(__DIR__.'/nuers-merchant-loading-verification.json', json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($out, JSON_THROW_ON_ERROR).PHP_EOL;
exit($out['verified'] ? 0 : 1);
