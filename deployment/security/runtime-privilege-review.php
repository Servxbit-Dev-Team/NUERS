<?php
// Read-only, NUERS-scoped metadata. Credentials and authentication hashes are never emitted.
if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) exit(77);
try {
    $base = '/var/www/vhosts/nuers.net/httpdocs';
    require $base.'/vendor/autoload.php';
    $app = require $base.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['logging.default' => 'stderr']);
    $db = Illuminate\Support\Facades\DB::connection();
    if ($db->getDatabaseName() !== 'NUERS') throw new RuntimeException('Unexpected database.');
    require_once __DIR__.'/RuntimeDatabasePrivileges.php';
    $runtime = app(App\Services\RuntimeDatabasePrivileges::class)->inspect();
    $admin = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock', 'admin', trim(file_get_contents('/etc/psa/.psa.shadow')), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $triggers = $admin->query("SELECT DEFINER AS principal, COUNT(*) AS trigger_count FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='NUERS' GROUP BY DEFINER")->fetchAll(PDO::FETCH_ASSOC);
    $principals = $admin->query("SELECT User AS account, Host AS host, Select_priv, Insert_priv, Update_priv, Delete_priv, Create_priv, Drop_priv, Alter_priv, Grant_priv, Trigger_priv FROM mysql.db WHERE Db='NUERS'")->fetchAll(PDO::FETCH_ASSOC);
    $current = $admin->query("SELECT USER AS account, SUBSTRING_INDEX(HOST,':',1) AS source, COUNT(*) AS connections FROM information_schema.PROCESSLIST WHERE DB='NUERS' GROUP BY USER, SUBSTRING_INDEX(HOST,':',1)")->fetchAll(PDO::FETCH_ASSOC);
    $routines = (int) $admin->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='NUERS'")->fetchColumn();
    $events = (int) $admin->query("SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA='NUERS'")->fetchColumn();
    echo json_encode(['observed_at' => gmdate('c'), 'runtime' => $runtime, 'schema_principals' => $principals, 'trigger_definers' => $triggers, 'active_nuers_connections' => $current, 'routines' => $routines, 'events' => $events], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) { fwrite(STDERR, "Private database privilege inspection failed; no raw database error disclosed.\n"); exit(1); }
