<?php
/** Operator-reviewed, same-host drill. No production replacement or external data transfer. */
require '/var/www/vhosts/nuers.net/httpdocs/vendor/autoload.php';
$app = require '/var/www/vhosts/nuers.net/httpdocs/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
umask(0077);
$db = 'nuers_vapt_restore_20261029';
[$dir, $key] = app(App\Services\LocalRecoveryBackup::class)->settings();
$archive = $dir.'/20261029-125416-d617c242e9f5787e.nuers';
$target = dirname($dir).'/vapt-restore-20261029';
if (! is_file($archive) || file_exists($target)) throw new RuntimeException('Expected archive or fresh target unavailable.');
$admin = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock', 'admin', trim(file_get_contents('/etc/psa/.psa.shadow')), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if ($admin->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$db'")->fetchColumn()) throw new RuntimeException('Temporary database already exists; never overwrite.');
$created = false;
$started = microtime(true);
try {
    $manifest = app(App\Services\RecoveryArchive::class)->restore($archive, $target, $key);
    $admin->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $defaults = $target.'/client.cnf';
    $password = trim(file_get_contents('/etc/psa/.psa.shadow'));
    file_put_contents($defaults, "[client]\nuser=admin\npassword=\"".str_replace(['\\', '"'], ['\\\\', '\\"'], $password)."\"\nsocket=/var/run/mysqld/mysqld.sock\n");
    chmod($defaults, 0600);
    $proc = new Symfony\Component\Process\Process(['/usr/bin/mysql', '--defaults-extra-file='.$defaults, '--database='.$db]);
    $proc->setInput(fopen($target.'/database.sql', 'r'));
    $proc->setTimeout(300);
    $proc->run();
    if (! $proc->isSuccessful()) throw new RuntimeException('Isolated import failed; no raw SQL or credentials disclosed.');
    unlink($defaults);
    $tables = $admin->query('SHOW TABLES FROM `'.$db.'`')->fetchAll(PDO::FETCH_COLUMN);
    $counts = [];
    foreach (['users', 'merchants', 'transaction_receipts', 'merchant_transactions', 'merchant_wallets', 'merchant_wallet_entries', 'eis_audit_events'] as $t) {
        $counts[$t] = (int) $admin->query('SELECT COUNT(*) FROM `'.$db.'`.`'.$t.'`')->fetchColumn();
    }
    config(['database.connections.vapt_restore'=>array_merge(config('database.connections.mysql'), ['database'=>$db, 'username'=>'admin', 'password'=>$password, 'unix_socket'=>'/var/run/mysqld/mysqld.sock']), 'database.default'=>'vapt_restore']);
    Illuminate\Support\Facades\DB::purge('vapt_restore');
    $checks = app(App\Services\OperationsHealth::class)->integrity();
    if (! $checks['valid']) throw new RuntimeException('Restored ledger or audit integrity mismatch.');
    $result = ['observed_at'=>date('c'), 'archive_id'=>basename($archive, '.nuers'), 'isolated_import_passed'=>true, 'table_count'=>count($tables), 'record_counts'=>$counts, 'integrity'=>$checks, 'files_authenticated'=>count($manifest['files']), 'elapsed_seconds'=>round(microtime(true)-$started, 3), 'scope'=>'Same-host isolated database import and object verification; no production replacement, traffic cutover, host rebuild or offsite restore', 'production_outage_rto_verified'=>false, 'production_rpo_verified'=>false];
} finally {
    Illuminate\Support\Facades\DB::disconnect('vapt_restore');
    if ($created) $admin->exec('DROP DATABASE `'.$db.'`');
    if (is_dir($target)) Illuminate\Support\Facades\File::deleteDirectory($target);
    sodium_memzero($key);
}
$result['temporary_plaintext_removed'] = ! file_exists($target);
$result['temporary_database_removed'] = ! $admin->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$db'")->fetchColumn();
echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
