<?php
// CLI-only, read-only diagnostics. No credentials or document contents are printed.
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
set_time_limit(50);
$root = __DIR__.'/httpdocs';
if (basename(__DIR__) !== 'nuers.net' || !is_file($root.'/artisan')) throw new RuntimeException('Wrong target');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$out = ['checked_at' => gmdate('c'), 'read_only' => true];
$flush = function () use (&$out) {
    file_put_contents(__DIR__.'/nuers-merchant-loading-diagnosis.json', json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
};
try {
    $version = DB::selectOne('SELECT VERSION() AS version')->version;
    $out['database_version'] = $version;
    if (str_contains($version, 'MariaDB')) DB::statement('SET SESSION max_statement_time=12');
    else DB::statement('SET SESSION max_execution_time=12000');
    $userId = DB::table('users')->where('email', 'goldenbayfreshseafood@gmail.com')->value('id');
    $merchant = DB::table('merchants')->where('merchant_account_id', $userId)->first();
    $out['account_found'] = (bool)$userId;
    $out['business_found'] = (bool)$merchant;
    if (!$merchant) throw new RuntimeException('Target business unavailable');
    $out['business_status'] = $merchant->status;
    foreach (['transaction_receipts','business_invoices','merchant_transactions'] as $table) {
        $out['counts'][$table] = DB::table($table)->where('merchant_id', $merchant->id)->count();
    }
    $flush();
    $st = microtime(true);
    $q = app(App\Services\IssuedDocumentLedger::class)->query((string)$merchant->id);
    $out['query_build_ms'] = round((microtime(true)-$st)*1000);
    $flush();
    $st = microtime(true);
    $rows = $q->orderByDesc('recorded_at')->limit(10)->get();
    $out['first_ten_query_ms'] = round((microtime(true)-$st)*1000);
    $out['first_ten_count'] = $rows->count();
    $flush();
    $st = microtime(true);
    $r = $rows->first(fn($r) => in_array($r->document_source, ['invoice','receipt'], true));
    if ($r) {
        $inspection = app(App\Services\EisDocumentReadiness::class)->inspect($r->document_source, $r->document_id);
        $out['single_inspection_ms'] = round((microtime(true)-$st)*1000);
        $out['single_financial_validation_passed'] = $inspection['financial_validation_passed'];
    }
    $out['completed'] = true;
} catch (Throwable $e) {
    $out['completed'] = false;
    $out['error_class'] = get_class($e);
    $out['error_code'] = $e->getCode();
    // Query exception messages can contain SQL, names, and data: disclose only a category.
    $out['error_category'] = str_contains(strtolower($e->getMessage()), 'time') ? 'query_timeout' : 'diagnostic_failed';
}
$flush();
echo json_encode($out, JSON_THROW_ON_ERROR).PHP_EOL;
