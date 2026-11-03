<?php

namespace App\Console\Commands;

use App\Support\SchemaGuardScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only report of how far this database is from what the code expects. Safe to run against a live database or a
 * copy: it only reads the migrations table and the schema. Exit code 1 means there is drift to resolve.
 */
class SchemaDrift extends Command
{
    protected $signature = 'schema:drift {--json : machine-readable output}';

    protected $description = 'Report pending migrations and the schema checks in the code that are firing on this database (read-only).';

    public function handle(SchemaGuardScanner $scanner): int
    {
        $migrator = app('migrator');
        $repository = $migrator->getRepository();
        $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
        $report = ['database' => config('database.default').':'.config('database.connections.'.config('database.default').'.database')];

        if (! $repository->repositoryExists()) {
            $report['migrations_table'] = 'missing';
            $report['pending'] = $files;
            $report['recorded_without_file'] = [];
        } else {
            $ran = $repository->getRan();
            $report['pending'] = array_values(array_diff($files, $ran));
            $report['recorded_without_file'] = array_values(array_diff($ran, $files));
        }

        $guards = $scanner->scan();
        $missing = [];
        $dynamic = 0;
        foreach ($guards as $guard) {
            if ($guard['dynamic']) {
                $dynamic++;

                continue;
            }
            $tableExists = Schema::hasTable($guard['table']);
            if (! $tableExists) {
                $missing[$guard['table']]['(table)'][] = $guard['file'].':'.$guard['line'];

                continue;
            }
            foreach ($guard['columns'] as $column) {
                if (! Schema::hasColumn($guard['table'], $column)) {
                    $missing[$guard['table']][$column][] = $guard['file'].':'.$guard['line'];
                }
            }
        }
        ksort($missing);
        $report['tables_no_migration_creates'] = $this->orphanTables();
        $report['guards'] = ['total' => count($guards), 'dynamic' => $dynamic, 'files' => count(array_unique(array_column($guards, 'file')))];
        $report['guard_targets_missing'] = $missing;
        $drift = $report['pending'] !== [] || $report['recorded_without_file'] !== [] || $missing !== [] || $report['tables_no_migration_creates'] !== [];

        if ($this->option('json')) {
            $this->line(json_encode($report + ['drift' => $drift], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $drift ? self::FAILURE : self::SUCCESS;
        }

        $this->line('Database: '.$report['database']);
        $this->line('Pending migrations: '.count($report['pending']));
        foreach ($report['pending'] as $name) {
            $this->line("  - $name");
        }
        if ($report['recorded_without_file']) {
            $this->warn('Recorded as run but no file in this checkout: '.count($report['recorded_without_file']));
            foreach ($report['recorded_without_file'] as $name) {
                $this->line("  - $name");
            }
        }
        $this->line("Schema checks in code: {$report['guards']['total']} across {$report['guards']['files']} files ({$dynamic} with a computed name, not checked).");
        if ($missing) {
            $this->warn('Checks whose table or column is missing here (the code takes its fallback path):');
            foreach ($missing as $table => $columns) {
                foreach ($columns as $column => $places) {
                    $this->line("  - $table.$column  ".implode(', ', array_slice($places, 0, 3)).(count($places) > 3 ? ' (+'.(count($places) - 3).')' : ''));
                }
            }
        }
        if ($report['tables_no_migration_creates']) {
            $this->warn('Tables in this database that no migration in this checkout creates (made by hand, by an import, or by an older schema):');
            foreach ($report['tables_no_migration_creates'] as $name) {
                $this->line("  - $name");
            }
        }
        $drift ? $this->error('Drift found.') : $this->info('No drift: every migration has run and every schema check finds what it looks for.');

        return $drift ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int,string> */
    private function orphanTables(): array
    {
        $created = ['migrations', 'sqlite_sequence'];
        foreach (glob(database_path('migrations/*.php')) as $file) {
            $code = file_get_contents($file);
            if (preg_match_all('/Schema::create\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $code, $m)) {
                $created = array_merge($created, $m[1]);
            }
            // A table created in a loop: foreach (['a', 'b'] as $table) { Schema::create($table, ...) }
            if (str_contains($code, 'Schema::create($') && preg_match_all('/foreach\s*\(\s*\[([^\]]*)\]\s*as\s+\$/', $code, $loops)) {
                foreach ($loops[1] as $list) {
                    preg_match_all('/[\'"]([a-z][a-z0-9_]*)[\'"]/', $list, $names);
                    $created = array_merge($created, $names[1]);
                }
            }
            if (preg_match_all('/create\s+table\s+(?:if\s+not\s+exists\s+)?[`"]?([A-Za-z0-9_]+)/i', $code, $m)) {
                $created = array_merge($created, $m[1]);
            }
        }
        $present = array_column(Schema::getTables(), 'name');

        return array_values(array_diff($present, array_unique($created)));
    }
}
