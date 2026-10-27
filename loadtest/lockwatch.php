<?php

/*
 * Samples InnoDB lock waits while a load run is in progress and tallies which table/index requests are waiting on.
 *
 *   php loadtest/lockwatch.php <seconds> [interval_ms=100]
 *
 * Needs a MySQL user that can read performance_schema (the throwaway root). Same safety guard as seed.php.
 */

if (getenv('NUERS_LOADTEST') !== '1' || ! in_array((string) getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true) || ! str_contains((string) getenv('DB_DATABASE'), 'load')) {
    fwrite(STDERR, "Refusing to run against a non-load-test database.\n");
    exit(1);
}

$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT') ?: 3306, getenv('DB_DATABASE')), getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$seconds = (int) ($argv[1] ?? 10);
$interval = (int) ($argv[2] ?? 100);
$status = fn () => array_column($pdo->query("show global status where variable_name in ('Innodb_row_lock_waits','Innodb_row_lock_time','Innodb_row_lock_time_avg','Innodb_row_lock_time_max')")->fetchAll(PDO::FETCH_NUM), 1, 0);
$before = $status();

$sql = 'select l.object_name, l.index_name, l.lock_mode, count(*) c from performance_schema.data_lock_waits w join performance_schema.data_locks l on l.engine_lock_id = w.requesting_engine_lock_id group by l.object_name, l.index_name, l.lock_mode';
$tally = [];
$samples = 0;
$withWaits = 0;
$waiters = 0;
$end = microtime(true) + $seconds;
while (microtime(true) < $end) {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $samples++;
    if ($rows) { $withWaits++; }
    foreach ($rows as $r) {
        $k = "{$r['object_name']}.{$r['index_name']} {$r['lock_mode']}";
        $tally[$k] = ($tally[$k] ?? 0) + (int) $r['c'];
        $waiters += (int) $r['c'];
    }
    usleep($interval * 1000);
}
$after = $status();
arsort($tally);
echo json_encode([
    'samples' => $samples, 'samples_with_any_lock_wait' => $withWaits, 'avg_waiting_locks_per_sample' => round($waiters / max(1, $samples), 2),
    'waits_by_table_index_mode' => array_slice($tally, 0, 8, true),
    'row_lock_waits_delta' => (int) $after['Innodb_row_lock_waits'] - (int) $before['Innodb_row_lock_waits'],
    'row_lock_time_ms_delta' => (int) $after['Innodb_row_lock_time'] - (int) $before['Innodb_row_lock_time'],
    'row_lock_time_avg_ms' => (int) $after['Innodb_row_lock_time_avg'], 'row_lock_time_max_ms' => (int) $after['Innodb_row_lock_time_max'],
], JSON_PRETTY_PRINT)."\n";
