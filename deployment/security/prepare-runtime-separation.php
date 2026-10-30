<?php
/** Root-only preparation. Creates only a localhost DML principal; does not switch production. */
if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) exit(77);
// Retired one-time cutover: retained as an audit record, not a reusable deployment tool.
fwrite(STDERR, "This approved cutover has completed. Use the current root-owned operator service; do not replay this historical script.\n");
exit(78);
umask(0077);
$base = '/var/www/vhosts/nuers.net/httpdocs';
$private = '/var/www/vhosts/nuers.net/private-recovery/operator';
try {
    require $base.'/vendor/autoload.php';
    $app = require $base.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['logging.default' => 'stderr']);
    $c = Illuminate\Support\Facades\DB::connection()->getConfig();
    if (! $app->environment('production') || $c['database'] !== 'NUERS' || $c['unix_socket'] !== '/var/run/mysqld/mysqld.sock') throw new RuntimeException('Production guard failed.');
    if (file_exists($private)) throw new RuntimeException('Operator directory already exists; review instead of overwriting.');
    $admin = new PDO('mysql:unix_socket='.$c['unix_socket'], 'admin', trim(file_get_contents('/etc/psa/.psa.shadow')), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($admin->query("SELECT COUNT(*) FROM mysql.user WHERE User='nuers_runtime'")->fetchColumn()) throw new RuntimeException('Runtime principal already exists.');
    mkdir($private, 0700);
    $password = bin2hex(random_bytes(32));
    $runtime = array_intersect_key($c, array_flip(['host', 'port', 'database', 'username', 'password', 'unix_socket']));
    $operator = $runtime;
    $runtime['username'] = 'nuers_runtime';
    $runtime['password'] = $password;
    $runtime['host'] = 'localhost';
    // Preserve an operator rollback/migration profile outside the web root before any cutover.
    foreach (['database.json' => $operator, 'runtime.json' => $runtime] as $name => $profile) {
        file_put_contents($private.'/'.$name, json_encode($profile, JSON_THROW_ON_ERROR));
        chmod($private.'/'.$name, 0600);
    }
    $admin->exec("CREATE USER 'nuers_runtime'@'localhost' IDENTIFIED BY ".$admin->quote($password));
    $admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `NUERS`.* TO 'nuers_runtime'@'localhost'");
    $pdo = new PDO('mysql:unix_socket='.$c['unix_socket'].';dbname=NUERS', 'nuers_runtime', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $result = ['observed_at' => gmdate('c'), 'principal' => $pdo->query('SELECT CURRENT_USER()')->fetchColumn(), 'production_switched' => false];
    $pdo->beginTransaction();
    try {
        $key = 'nuers-runtime-probe-'.bin2hex(random_bytes(8));
        $q = $pdo->prepare('INSERT INTO cache (`key`,value,expiration) VALUES (?, ?, ?)');
        $q->execute([$key, 'synthetic-probe', time() + 60]);
        $q = $pdo->prepare('UPDATE cache SET value=? WHERE `key`=?'); $q->execute(['updated', $key]);
        $q = $pdo->prepare('SELECT value FROM cache WHERE `key`=? FOR UPDATE'); $q->execute([$key]);
        if ($q->fetchColumn() !== 'updated') throw new RuntimeException('DML/locking probe failed.');
        $q = $pdo->prepare('DELETE FROM cache WHERE `key`=?'); $q->execute([$key]);
        $result['dml_and_row_lock_passed'] = true;
        $merchant = $pdo->query('SELECT id FROM merchants ORDER BY id LIMIT 1')->fetchColumn();
        if (! $merchant) throw new RuntimeException('No merchant available for rolled-back trigger probe.');
        $id = 'vapt-runtime-'.bin2hex(random_bytes(10));
        $q = $pdo->prepare("INSERT INTO transaction_receipts (id, merchant_id, receipt_number, status, total_due, gross_amount, created_at, updated_at) VALUES (?, ?, ?, 'issued', 1, 1, NOW(), NOW())");
        $q->execute([$id, $merchant, $id]);
        $q = $pdo->prepare("SELECT COUNT(*) FROM ledger_dirty_documents WHERE document_id=? AND source='receipt'"); $q->execute([$id]);
        if (! $q->fetchColumn()) throw new RuntimeException('Existing trigger definer failed under DML runtime.');
        $result['source_trigger_passed'] = true;
    } finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
    $denied = [];
    foreach (['create_table' => 'CREATE TABLE NUERS.nuers_runtime_denial_probe (id INT)', 'read_system_users' => 'SELECT User FROM mysql.user'] as $name => $sql) {
        try {
            $pdo->exec($sql);
            if ($name === 'create_table') $admin->exec('DROP TABLE NUERS.nuers_runtime_denial_probe');
            throw new RuntimeException('Excess privilege detected.');
        } catch (PDOException $e) {
            if (! in_array((int) ($e->errorInfo[1] ?? 0), [1044, 1142], true)) throw $e;
            $denied[$name] = true;
        }
    }
    $result['denied_operations'] = $denied;
    $result['all_probes_rolled_back'] = true;
    $result['legacy_principal'] = 'Retained for existing trigger definers and reviewed operator recovery. Remote client dependency/rotation requires owner confirmation.';
    file_put_contents($private.'/preparation.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) { fwrite(STDERR, "Runtime separation preparation failed; preserved private evidence for operator review. No credential-bearing exception disclosed.\n"); exit(1); }
