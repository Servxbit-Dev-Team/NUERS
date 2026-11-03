<?php

namespace Tests\Feature;

use App\Support\SchemaGuardScanner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaDriftTest extends TestCase
{
    /** Guards on tables no migration creates. Keep this list short: each entry is code written for a schema nobody can build. */
    private const GUARDS_WITHOUT_MIGRATION = ['transaction_receipt_items'];

    private function migrated(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    private function report(): array
    {
        Artisan::call('schema:drift', ['--json' => true]);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_scanner_reads_table_and_column_checks_and_flags_computed_names(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'guard').'.php';
        file_put_contents($file, <<<'PHP'
<?php
if (Schema::hasTable('alpha') && SchemaMap::hasColumn('beta', 'gamma')) {}
$x = Schema::hasColumn('delta', $column);
$y = Schema::hasTable($table);
$z = Schema::hasColumns('eps', ['one', "two"]);
PHP);
        $hits = (new SchemaGuardScanner)->scanFile($file);
        unlink($file);

        $this->assertCount(5, $hits);
        $this->assertSame(['alpha', [], false], [$hits[0]['table'], $hits[0]['columns'], $hits[0]['dynamic']]);
        $this->assertSame(['beta', ['gamma'], false], [$hits[1]['table'], $hits[1]['columns'], $hits[1]['dynamic']]);
        $this->assertTrue($hits[2]['dynamic']);
        $this->assertTrue($hits[3]['dynamic']);
        $this->assertSame(['eps', ['one', 'two'], false], [$hits[4]['table'], $hits[4]['columns'], $hits[4]['dynamic']]);
    }

    public function test_a_fully_migrated_database_has_no_drift_apart_from_the_known_dead_guard(): void
    {
        $this->migrated();
        $report = $this->report();

        $this->assertSame([], $report['pending']);
        $this->assertSame([], $report['recorded_without_file']);
        $this->assertSame([], $report['tables_no_migration_creates'], 'A migration creates tables the report cannot see, or a table is created outside migrations.');
        $this->assertSame(self::GUARDS_WITHOUT_MIGRATION, array_keys($report['guard_targets_missing']), 'A new schema check refers to a table or column that no migration creates. Add the migration, or drop the check.');
        $this->assertGreaterThan(150, $report['guards']['total']);
    }

    public function test_a_pending_migration_is_reported_and_fails_the_command(): void
    {
        $this->migrated();
        $last = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f, '.php'))->sort()->last();
        DB::table('migrations')->where('migration', $last)->delete();
        DB::table('migrations')->insert(['migration' => '2020_01_01_000000_not_in_this_checkout', 'batch' => 99]);

        $code = Artisan::call('schema:drift', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(1, $code);
        $this->assertSame([$last], $report['pending']);
        $this->assertSame(['2020_01_01_000000_not_in_this_checkout'], $report['recorded_without_file']);
    }

    public function test_a_table_made_outside_migrations_is_reported(): void
    {
        $this->migrated();
        DB::statement('create table hand_made_import (id integer primary key)');

        $this->assertSame(['hand_made_import'], $this->report()['tables_no_migration_creates']);
    }

    public function test_request_code_uses_the_cached_schema_lookup(): void
    {
        $offenders = [];
        foreach ([base_path('app'), base_path('routes')] as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if ($file->getExtension() !== 'php' || preg_match('#app/(Support/SchemaMap|Console/Commands/SchemaDrift)\.php$#', $path)) {
                    continue;
                }
                if (preg_match('/(?<![A-Za-z])Schema::(hasTable|hasColumn)\(/', file_get_contents($path))) {
                    $offenders[] = basename($path);
                }
            }
        }
        $this->assertSame([], $offenders, 'Use App\Support\SchemaMap::hasTable/hasColumn: a raw check is one catalogue query per call.');
    }
}
