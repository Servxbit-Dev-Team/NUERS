<?php
/** Controlled cutover after explicitly approved preparation. No merchant data is removed. */
if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) exit(77);
// Retired one-time cutover: retained as an audit record, not a reusable deployment tool.
fwrite(STDERR, "This approved cutover has completed. Use the current root-owned operator service; do not replay this historical script.\n");
exit(78);
umask(0077);
$base = '/var/www/vhosts/nuers.net/httpdocs';
$stage = '/var/www/vhosts/nuers.net/private-recovery/vapt-20261029';
$private = '/var/www/vhosts/nuers.net/private-recovery/operator';
$backup = $private.'/cutover-'.gmdate('Ymd-His');
require $base.'/vendor/autoload.php';
$php = '/opt/plesk/php/8.4/bin/php';
$owner = posix_getpwuid(fileowner($base.'/.env'))['name'];
$group = filegroup($base.'/.env');
$files = ['config/operations.php' => 'operations.php', 'routes/operations-console.php' => 'operations-console.php', 'app/Services/RuntimeDatabasePrivileges.php' => 'RuntimeDatabasePrivileges.php'];
$restore = [];
$changed = false;
$run = function (array $command, bool $asApp = false, int $timeout = 120) use ($base, $owner) {
    $p = new Symfony\Component\Process\Process($asApp ? array_merge(['/usr/sbin/runuser', '-u', $owner, '--'], $command) : $command, $base);
    $p->setTimeout($timeout); $p->run();
    if (! $p->isSuccessful()) throw new RuntimeException('Controlled command failed; private rollback will run.');
    return $p->getOutput();
};
try {
    $prepared = json_decode(file_get_contents($private.'/preparation.json'), true, flags: JSON_THROW_ON_ERROR);
    if (! ($prepared['source_trigger_passed'] ?? false) || ! ($prepared['denied_operations']['create_table'] ?? false)) throw new RuntimeException('Preparation evidence missing.');
    $runtime = json_decode(file_get_contents($private.'/runtime.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($runtime['username'] !== 'nuers_runtime' || $runtime['database'] !== 'NUERS') throw new RuntimeException('Identity mismatch.');
    $env = file_get_contents($base.'/.env');
    if (str_contains($env, 'nuers_runtime')) throw new RuntimeException('Already switched; inspect current state.');
    mkdir($backup, 0700);
    foreach (array_merge(['.env'], array_keys($files)) as $path) {
        if (is_file($base.'/'.$path)) {
            $dest = $backup.'/'.str_replace('/', '__', $path);
            copy($base.'/'.$path, $dest); chmod($dest, 0600); $restore[$path] = $dest;
        } else $restore[$path] = null;
    }
    $changed = true;
    foreach ($files as $path => $source) {
        if (! is_file($stage.'/'.$source)) throw new RuntimeException('Missing staged file.');
        copy($stage.'/'.$source, $base.'/'.$path); chmod($base.'/'.$path, 0644); chown($base.'/'.$path, $owner); chgrp($base.'/'.$path, $group);
    }
    $values = ['DB_USERNAME' => 'nuers_runtime', 'DB_PASSWORD' => $runtime['password'], 'DB_HOST' => 'localhost', 'DB_SOCKET' => $runtime['unix_socket'], 'DB_URL' => '', 'NUERS_BACKUP_APP_SCHEDULER_ENABLED' => 'false'];
    foreach ($values as $key => $value) {
        $line = $key.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        $env = preg_match('/^'.preg_quote($key, '/').'=/m', $env) ? preg_replace_callback('/^'.preg_quote($key, '/').'=[^\r\n]*/m', fn () => $line, $env) : rtrim($env)."\n".$line."\n";
    }
    $next = $base.'/.env.runtime-cutover';
    file_put_contents($next, $env, LOCK_EX); chmod($next, 0600); chown($next, $owner); chgrp($next, $group); rename($next, $base.'/.env');
    $run([$php, 'artisan', 'config:cache'], true);
    $grants = json_decode($run([$php, 'artisan', 'operations:database-privileges'], true), true, flags: JSON_THROW_ON_ERROR);
    if (! ($grants['least_privilege_passed'] ?? false)) throw new RuntimeException('Post-switch grants check failed.');
    $health = json_decode($run([$php, 'artisan', 'operations:check'], true), true, flags: JSON_THROW_ON_ERROR);
    if (! ($health['acceptance_ready'] ?? false)) throw new RuntimeException('Application health check failed.');
    $integrity = json_decode($run([$php, 'artisan', 'operations:integrity'], true), true, flags: JSON_THROW_ON_ERROR);
    if (! ($integrity['valid'] ?? false)) throw new RuntimeException('Integrity check failed.');
    foreach (['service', 'timer'] as $suffix) {
        copy($stage.'/nuers-operator-backup.'.$suffix, '/etc/systemd/system/nuers-operator-backup.'.$suffix);
        chmod('/etc/systemd/system/nuers-operator-backup.'.$suffix, 0644);
    }
    $run(['systemctl', 'daemon-reload']);
    $run(['systemctl', 'enable', '--now', 'nuers-operator-backup.timer']);
    $run([$php, 'artisan', 'queue:restart'], true);
    $run(['systemctl', 'reload', 'plesk-php84-fpm']);
    $result = ['observed_at' => gmdate('c'), 'production_switched' => true, 'runtime' => $grants, 'acceptance_ready' => $health['acceptance_ready'], 'integrity' => $integrity, 'operator_backup_timer' => trim($run(['systemctl', 'is-active', 'nuers-operator-backup.timer'])), 'rollback_directory' => $backup];
    file_put_contents($private.'/cutover-result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    if ($changed) {
        foreach ($restore as $path => $source) {
            if ($source === null) { if (is_file($base.'/'.$path)) unlink($base.'/'.$path); continue; }
            copy($source, $base.'/'.$path); chmod($base.'/'.$path, $path === '.env' ? 0600 : 0644); chown($base.'/'.$path, $owner); chgrp($base.'/'.$path, $group);
        }
        try { $run([$php, 'artisan', 'config:cache'], true); $run(['systemctl', 'reload', 'plesk-php84-fpm']); } catch (Throwable) { }
        $p = new Symfony\Component\Process\Process(['systemctl', 'disable', '--now', 'nuers-operator-backup.timer']); $p->run();
    }
    fwrite(STDERR, "Runtime cutover failed. Original application files/environment restored when a cutover had started. Inspect health before retrying; no credentials disclosed.\n"); exit(1);
}
