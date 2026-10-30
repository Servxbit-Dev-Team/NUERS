<?php
/** Standalone root-owned runner. Never load application PHP, vendor, .env or caches. */
if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) exit(77);
umask(0077);
date_default_timezone_set('Asia/Manila');
$root = '/var/lib/nuers-operator';
$base = '/var/www/vhosts/nuers.net/httpdocs';
$stage = null; $lock = null; $key = null; $failed = false;
$private = function (string $path, bool $dir = false): void {
    if (is_link($path) || ! ($dir ? is_dir($path) : is_file($path)) || fileowner($path) !== 0 || (fileperms($path) & 0077)) throw new RuntimeException('Private operator ownership check failed.');
};
try {
    foreach (['/usr', '/usr/local', '/usr/local/lib', __DIR__, __FILE__, __DIR__.'/StandaloneRecoveryArchive.php'] as $path) {
        if (is_link($path) || fileowner($path) !== 0 || (fileperms($path) & 0022)) throw new RuntimeException('Operator code ancestry is not root-controlled.');
    }
    $private($root, true); $private($root.'/database.json'); $private($root.'/archive.key'); $private($root.'/archives', true);
    $credentials = json_decode(file_get_contents($root.'/database.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($credentials['database'] ?? null) !== 'NUERS' || ($credentials['unix_socket'] ?? null) !== '/var/run/mysqld/mysqld.sock') throw new RuntimeException('Database identity guard failed.');
    $key = file_get_contents($root.'/archive.key');
    if (strlen($key) !== 32) throw new RuntimeException('Invalid recovery key.');
    require __DIR__.'/StandaloneRecoveryArchive.php';
    $service = new NuersOperator\RecoveryArchive;
    $lock = fopen($root.'/.capture.lock', 'c');
    if (! flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Backup already running.');
    if (($argv[1] ?? '') === '--verify-latest') {
        $last = json_decode(file_get_contents($root.'/last-backup.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{16}$/D', $last['archive_id'])) throw new RuntimeException('Invalid archive ID.');
        $stage = $root.'/.verify-'.bin2hex(random_bytes(10));
        $path = $root.'/archives/'.$last['archive_id'].'.nuers';
        if (! hash_equals($last['sha256'], hash_file('sha256', $path))) throw new RuntimeException('Archive checksum mismatch.');
        $manifest = $service->restore($path, $stage, $key);
        echo json_encode(['archive_id' => $last['archive_id'], 'archive_authenticated' => true, 'file_hashes_verified' => true, 'files' => count($manifest['files']), 'database_import_tested' => false, 'offsite' => false], JSON_THROW_ON_ERROR)."\n";
    } else {
        if (count($argv) > 1 || file_exists($base.'/.nuers-deploying')) throw new RuntimeException('Unexpected argument or deployment in progress.');
        $pdo = new PDO('mysql:unix_socket='.$credentials['unix_socket'].';dbname=NUERS', $credentials['username'], $credentials['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='NUERS' AND TABLE_TYPE='BASE TABLE' AND ENGINE <> 'InnoDB'")->fetchColumn()) throw new RuntimeException('Nontransactional table detected.');
        $started = microtime(true); $id = gmdate('Ymd-His').'-'.bin2hex(random_bytes(8));
        $stage = $root.'/.stage-'.$id; mkdir($stage, 0700);
        $quote = fn ($v) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $v).'"';
        $cnf = "[client]\nuser=".$quote($credentials['username'])."\npassword=".$quote($credentials['password'])."\nsocket=".$quote($credentials['unix_socket'])."\n";
        file_put_contents($stage.'/client.cnf', $cnf, LOCK_EX);
        $command = ['/usr/bin/mysqldump', '--defaults-file='.$stage.'/client.cnf', '--single-transaction', '--quick', '--skip-lock-tables', '--hex-blob', '--no-tablespaces', '--routines', '--events', '--triggers', '--result-file='.$stage.'/database.sql', '--', 'NUERS'];
        $proc = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $stage.'/dump-error.log', 'w']], $pipes, $root, ['PATH' => '/usr/bin:/bin', 'HOME' => '/root', 'LANG' => 'C.UTF-8']);
        if (! is_resource($proc) || proc_close($proc) !== 0 || ! filesize($stage.'/database.sql')) throw new RuntimeException('Database capture failed.');
        unlink($stage.'/client.cnf');
        $files = ['database.sql' => $stage.'/database.sql'];
        foreach (['private', 'public'] as $label) {
            $source = $base.'/storage/app/'.$label;
            foreach ([$base, $base.'/storage', $base.'/storage/app', $source] as $p) if (is_link($p)) throw new RuntimeException('Symlinked source root is not allowed.');
            if (! is_dir($source)) continue;
            $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
            foreach ($walk as $file) {
                if (! $file->isFile() || $file->isLink() || str_starts_with($file->getFilename(), '.ops-')) continue;
                $real = $file->getRealPath();
                if (! $real || ! str_starts_with($real, $source.'/')) throw new RuntimeException('Source path escaped approved storage.');
                $files['objects/'.$label.'/'.substr($real, strlen($source) + 1)] = $real;
            }
        }
        $total = array_sum(array_map('filesize', $files)); $free = disk_free_space($root);
        if ($total > 5 * 1024 ** 3 || $free === false || $free < $total * 3 + 1024 ** 3) throw new RuntimeException('Backup capacity check failed.');
        $archive = $root.'/archives/'.$id.'.nuers';
        $result = $service->create($files, $archive, $key, ['environment' => 'production', 'database_driver' => 'mysql', 'capture_started_at' => gmdate('c', (int) $started), 'software_version' => '1.0.0', 'application_secrets' => 'Excluded; separate custody required.', 'consistency' => 'Transactional database snapshot and checked object hashes; isolated import/reconciliation still required.']);
        if (file_exists($base.'/.nuers-deploying')) throw new RuntimeException('Deployment overlapped capture.');
        $result += ['archive_id' => $id, 'scope' => 'Database and private/public application objects', 'environment' => 'production', 'duration_seconds' => round(microtime(true) - $started, 3), 'offsite' => false, 'immutable' => false, 'runner' => 'standalone-root-owned'];
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        $q = $pdo->prepare("INSERT INTO operations_observations (id,payload,observed_at) VALUES ('backup',?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),observed_at=VALUES(observed_at)");
        $q->execute([$json, date('Y-m-d H:i:s')]);
        file_put_contents($root.'/last-backup.json.tmp', $json, LOCK_EX); rename($root.'/last-backup.json.tmp', $root.'/last-backup.json');
        echo $json."\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Standalone operator backup failed; inspect private configuration and health evidence. No credential-bearing error disclosed.\n");
    $failed = true;
} finally {
    if ($stage && is_dir($stage) && class_exists(NuersOperator\RecoveryArchive::class, false)) NuersOperator\RecoveryArchive::removeTree($stage);
    if (is_string($key)) sodium_memzero($key);
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}
if ($failed) exit(1);
