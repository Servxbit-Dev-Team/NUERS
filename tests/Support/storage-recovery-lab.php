<?php

// Synthetic, disposable, file-backed recovery exercise. Never opens the production database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('APP_ENV=testing is required.');
}
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use App\Services\{LocalRecoveryBackup, RecoveryArchive, OperationsHealth, MerchantWallet, BirCompliance};

$root = '/private/tmp/nuers-storage-lab-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    mkdir($root.'/archives', 0700);
    mkdir($root.'/storage', 0700);
    $key = random_bytes(32);
    file_put_contents($root.'/recovery.key', $key);
    chmod($root.'/recovery.key', 0600);
    touch($root.'/source.sqlite');
    chmod($root.'/source.sqlite', 0600);
    $app->useStoragePath($root.'/storage');
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $root.'/source.sqlite', 'notifications.dispatch_enabled' => false, 'mail.default' => 'array', 'cache.default' => 'array', 'queue.default' => 'sync', 'operations.backup.enabled' => true, 'operations.backup.directory' => $root.'/archives', 'operations.backup.key_file' => $root.'/recovery.key']);
    DB::purge('sqlite');
    Artisan::call('migrate', ['--force' => true]);
    foreach (['a','b'] as $m) {
        DB::table('merchants')->insert(['id' => 'lab-'.$m,'business_name' => 'Synthetic merchant '.$m,'tin' => $m === 'a' ? '111111111' : '222222222','status' => 'active']);
        DB::transaction(fn () => app(MerchantWallet::class)->credit('lab-'.$m, 10000, 'lab-credit-'.$m, 'SYNTHETIC', 'lab'));
        File::ensureDirectoryExists(storage_path('app/private/lab-'.$m), 0700);
        file_put_contents(storage_path('app/private/lab-'.$m.'/document.txt'), 'Synthetic private object '.$m);
        for ($i = 1; $i <= 20; $i++) {
            DB::table('transaction_receipts')->insert(['id' => 'lab-'.$m.'-'.$i,'merchant_id' => 'lab-'.$m,'receipt_number' => 'LAB-'.$i,'merchant_name' => 'Synthetic merchant '.$m,'merchant_tin' => $m === 'a' ? '111111111' : '222222222','status' => 'issued','total_due' => 100 + $i,'gross_amount' => 100 + $i]);
        }
    }
    app(BirCompliance::class)->audit('lab.checkpoint', null, 'synthetic-lab', []);
    $tables = ['merchants','transaction_receipts','merchant_wallets','merchant_wallet_entries','eis_audit_events','eis_audit_head','infra_events','infra_state'];
    $hashes = [];
    foreach ($tables as $t) {
        $hashes[$t] = hash('sha256', json_encode(DB::table($t)->orderBy($t === 'merchant_wallets' ? 'merchant_id' : 'id')->get(), JSON_THROW_ON_ERROR));
    }
    $sourceCommit = microtime(true);
    $backup = app(LocalRecoveryBackup::class)->create();
    $verified = app(LocalRecoveryBackup::class)->verify($backup['archive_id']);
    if (!$verified['database_verified']) {
        throw new RuntimeException('Archive DB verification failed.');
    }
    DB::table('transaction_receipts')->insert(['id' => 'lab-post-snapshot','merchant_id' => 'lab-a','receipt_number' => 'LAB-LATER','merchant_name' => 'Synthetic merchant a','merchant_tin' => '111111111','status' => 'issued','total_due' => 1,'gross_amount' => 1]);
    $outage = microtime(true);
    app(RecoveryArchive::class)->restore($root.'/archives/'.$backup['archive_id'].'.nuers', $root.'/recovery', $key);
    config(['database.connections.recovery' => ['driver' => 'sqlite','database' => $root.'/recovery/database.sqlite','foreign_key_constraints' => true], 'database.default' => 'recovery']);
    foreach ($tables as $t) {
        if (!hash_equals($hashes[$t], hash('sha256', json_encode(DB::table($t)->orderBy($t === 'merchant_wallets' ? 'merchant_id' : 'id')->get(), JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('Restored table differs: '.$t);
        }
    }
    if (!app(OperationsHealth::class)->integrity()['valid']) {
        throw new RuntimeException('Recovered ledger/audit check failed.');
    }
    foreach (['a','b'] as $m) {
        if (file_get_contents($root.'/recovery/objects/private/lab-'.$m.'/document.txt') !== 'Synthetic private object '.$m) {
            throw new RuntimeException('Object mismatch.');
        }
    }
    $result = ['scope' => 'Isolated synthetic SQLite database/object restore; not production RTO/RPO or failover','measured_at' => gmdate('c'),'software_version' => '1.0.0','synthetic_merchants' => 2,'restored_receipts' => DB::table('transaction_receipts')->count(),'wallet_and_audit_integrity' => true,'table_hashes_matched' => count($tables),'private_objects_matched' => 2,'lab_restore_seconds' => round(microtime(true) - $outage, 3),'lab_snapshot_age_at_simulated_outage_seconds' => round($outage - $sourceCommit, 3),'expected_post_snapshot_records_not_recovered' => 1,'production_restore_verified' => false,'offsite_verified' => false,'encrypted_archive_sha256' => $backup['sha256'],'archive_bytes' => $backup['size_bytes'],'archive_verification' => $verified];
    file_put_contents(__DIR__.'/../../output/storage-operations-audit/recovery-lab.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
} finally {
    DB::disconnect('sqlite');
    DB::disconnect('recovery');
    File::deleteDirectory($root);
}
