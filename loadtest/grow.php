<?php

/*
 * Grows transaction_receipts to a target row count by cloning existing rows with new ids and numbers,
 * so scaling of ledger queries can be timed at larger table sizes. Receipts only (no linked transactions),
 * so per-row query cost is understated.
 *
 *   php loadtest/grow.php <target_rows>
 *
 * Same safety guard as seed.php.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfg = config('database.connections.'.config('database.default'));
if (getenv('NUERS_LOADTEST') !== '1' || ! in_array((string) ($cfg['host'] ?? ''), ['127.0.0.1', 'localhost'], true) || ! str_contains((string) ($cfg['database'] ?? ''), 'load')) {
    fwrite(STDERR, "Refusing to run against a non-load-test database.\n");
    exit(1);
}

$target = (int) ($argv[1] ?? 10000);
$cols = array_column(DB::select("select column_name as c from information_schema.columns where table_schema = database() and table_name = 'transaction_receipts' order by ordinal_position"), 'c');
$select = implode(',', array_map(fn ($c) => match ($c) {
    'id' => 'UUID()', 'receipt_number' => "CONCAT('GROW-', UUID())", 'transaction_id' => 'NULL', 'series_number' => 'NULL', default => "`$c`",
}, $cols));
$insert = implode(',', array_map(fn ($c) => "`$c`", $cols));
$have = DB::table('transaction_receipts')->count();
while ($have < $target) {
    $n = min($have, $target - $have, 20000);
    DB::statement("insert into transaction_receipts ($insert) select $select from transaction_receipts where receipt_number not like 'GROW-%' limit $n");
    $have = DB::table('transaction_receipts')->count();
}
echo "transaction_receipts now has $have rows\n";
