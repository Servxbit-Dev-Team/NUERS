<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Operator-only local backup. Offsite custody, keys and production restore are separate requirements. */
class LocalRecoveryBackup
{
    public function settings(): array
    {
        $dir = realpath((string) config('operations.backup.directory'));
        $keyPath = realpath((string) config('operations.backup.key_file'));
        if (! $dir || ! $keyPath || ! is_dir($dir) || ! is_file($keyPath) || is_link(config('operations.backup.key_file')) || is_link(config('operations.backup.directory'))) {
            throw new RuntimeException('Provision a private backup directory and separate key file first.');
        }
        $base = realpath(base_path());
        if ($dir === $base || str_starts_with($dir, $base.'/') || str_starts_with($keyPath, $base.'/') || str_starts_with($keyPath, $dir.'/')) {
            throw new RuntimeException('Backup storage and the separate recovery key must be outside the application/web root.');
        }
        if ((fileperms($dir) & 0077) !== 0 || (fileperms($keyPath) & 0077) !== 0) {
            throw new RuntimeException('Require directory mode 0700 and recovery key mode 0600.');
        }
        $key = file_get_contents($keyPath);
        if (strlen($key) !== 32) {
            throw new RuntimeException('The recovery key must contain exactly 32 random binary bytes.');
        }

        return [$dir, $key];
    }

    public function create(): array
    {
        if (! config('operations.backup.enabled')) {
            throw new RuntimeException('Automated local recovery backups are not enabled. Existing provider backups are separate.');
        }
        [$dir, $key] = $this->settings();
        $available = disk_free_space($dir);
        if ($available === false || $available < config('operations.minimum_free_bytes')) {
            throw new RuntimeException('Insufficient free space to begin a backup safely.');
        }
        if (file_exists(base_path('.nuers-deploying'))) {
            throw new RuntimeException('A deployment is in progress. Retry after it completes.');
        }
        $lock = fopen($dir.'/.capture.lock', 'c');
        chmod($dir.'/.capture.lock', 0600);
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('A backup is already running.');
        }
        $oldMask = umask(0077);
        $id = gmdate('Ymd-His').'-'.bin2hex(random_bytes(8));
        $stage = $dir.'/.stage-'.$id;
        $started = microtime(true);
        $sourceAt = now()->toIso8601String();
        mkdir($stage, 0700);
        try {
            $driver = DB::connection()->getDriverName();
            $database = $stage.'/database.'.($driver === 'sqlite' ? 'sqlite' : 'sql');
            $this->database($database);
            $files = [basename($database) => $database];
            foreach (['private' => storage_path('app/private'), 'public' => storage_path('app/public')] as $label => $root) {
                if (! is_dir($root)) {
                    continue;
                }
                foreach (File::allFiles($root, true) as $file) {
                    if ($file->isLink() || str_starts_with($file->getFilename(), '.ops-')) {
                        continue;
                    }
                    $files['objects/'.$label.'/'.$file->getRelativePathname()] = $file->getPathname();
                }
            }
            $total = array_sum(array_map('filesize', $files));
            $free = disk_free_space($dir);
            if ($total > config('operations.backup.max_bytes') || $free === false || $free < $total * 3 + config('operations.minimum_free_bytes')) {
                throw new RuntimeException('Insufficient backup capacity or configured size limit exceeded.');
            }
            $context = ['environment' => app()->environment(), 'database_driver' => $driver, 'capture_started_at' => $sourceAt,
                'consistency' => 'Consistent database snapshot plus verified object inventory. Full application recovery still requires an isolated database import and reference reconciliation.',
                'application_secrets' => 'Excluded. Recover APP_KEY and integration secrets from separately controlled custody.',
                'software_version' => config('bir_compliance.solution_version')];
            $archive = $dir.'/'.$id.'.nuers';
            $result = app(RecoveryArchive::class)->create($files, $archive, $key, $context);
            if (file_exists(base_path('.nuers-deploying'))) {
                throw new RuntimeException('Deployment overlapped capture. Archive retained for investigation, not recorded as successful.');
            }
            $result += ['archive_id' => $id, 'scope' => 'Database and private/public application objects', 'environment' => app()->environment(), 'duration_seconds' => round(microtime(true) - $started, 3), 'offsite' => false, 'immutable' => false];
            app(OperationsHealth::class)->record('backup', $result);

            return $result;
        } finally {
            File::deleteDirectory($stage);
            sodium_memzero($key);
            umask($oldMask);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function database(string $destination): void
    {
        $c = DB::connection()->getConfig();
        if ($c['driver'] === 'sqlite') {
            if (! app()->environment('testing', 'local') || ($c['database'] ?? ':memory:') === ':memory:') {
                throw new RuntimeException('SQLite snapshot support is limited to isolated file-backed test/local environments.');
            }
            DB::statement('VACUUM INTO '.DB::connection()->getPdo()->quote($destination));
            chmod($destination, 0600);

            return;
        }
        if ($c['driver'] !== 'mysql') {
            throw new RuntimeException('No verified backup adapter for the configured database.');
        }
        $nonTransactional = DB::select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? AND ENGINE <> ?', [$c['database'], 'BASE TABLE', 'InnoDB']);
        if ($nonTransactional) {
            throw new RuntimeException('Consistent online backup requires all application tables to use InnoDB.');
        }
        $binary = (string) config('operations.backup.mysqldump');
        if (! str_starts_with($binary, '/') || ! is_executable($binary)) {
            throw new RuntimeException('Configure an installed, trusted mysqldump binary.');
        }
        $defaults = dirname($destination).'/mysql-client.cnf';
        $quote = fn ($v) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $v).'"';
        $content = "[client]\nuser=".$quote($c['username'])."\npassword=".$quote($c['password'])."\nhost=".$quote($c['host'])."\nport=".(int) $c['port']."\n";
        if (! empty($c['unix_socket'])) {
            $content .= 'socket='.$quote($c['unix_socket'])."\n";
        } elseif (! in_array($c['host'], ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('Remote database backup requires a separately reviewed TLS client profile.');
        }
        file_put_contents($defaults, $content, LOCK_EX);
        chmod($defaults, 0600);
        touch($destination);
        chmod($destination, 0600);
        try {
            $process = new Process([$binary, '--defaults-extra-file='.$defaults, '--single-transaction', '--quick', '--skip-lock-tables', '--hex-blob', '--no-tablespaces', '--routines', '--events', '--triggers', '--result-file='.$destination, '--', $c['database']]);
            $process->setTimeout(900);
            $process->run();
            if (! $process->isSuccessful() || filesize($destination) === 0) {
                throw new RuntimeException('Database backup failed. Inspect database backup privileges and the installed client; no credentials or raw output are disclosed.');
            }
        } finally {
            unlink($defaults);
        }
    }

    public function verify(string $archiveId): array
    {
        if (! preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{16}$/D', $archiveId)) {
            throw new RuntimeException('Use an archive identifier generated by this backup tool.');
        }
        [$dir, $key] = $this->settings();
        $archive = $dir.'/'.$archiveId.'.nuers';
        if (! is_file($archive) || is_link($archive)) {
            throw new RuntimeException('Local recovery archive not found.');
        }
        $target = $dir.'/.verify-'.bin2hex(random_bytes(12));
        $start = microtime(true);
        try {
            $manifest = app(RecoveryArchive::class)->restore($archive, $target, $key);
            $sqlite = ($manifest['context']['database_driver'] ?? '') === 'sqlite';
            $dbVerified = false;
            if ($sqlite && app()->environment('testing', 'local')) {
                $pdo = new \PDO('sqlite:'.$target.'/database.sqlite');
                $dbVerified = $pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok' && $pdo->query('PRAGMA foreign_key_check')->fetch() === false;
                $pdo = null;
            }
            $result = ['archive_id' => $archiveId, 'environment' => $manifest['context']['environment'] ?? 'unknown',
                'verified' => $dbVerified, 'archive_authenticated' => true, 'file_hashes_verified' => true,
                'database_verified' => $dbVerified, 'production_restore_verified' => false,
                'duration_seconds' => round(microtime(true) - $start, 3), 'files' => count($manifest['files']),
                'limitation' => $dbVerified ? 'Isolated SQLite lab verification. This is not a production RTO/RPO or failover test.' : 'Archive and file integrity passed. MySQL import and application recovery must be tested in a separately provisioned isolated environment.'];
            app(OperationsHealth::class)->record('recovery_test', $result);

            return $result;
        } finally {
            if (is_dir($target)) {
                File::deleteDirectory($target);
            }
            sodium_memzero($key);
        }
    }
}
