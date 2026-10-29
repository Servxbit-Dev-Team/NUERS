<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_graph_edges')) {
            return;
        }

        $indexName = 'tax_graph_edges_scope_source_target_relationship_unique';

        if ($this->isSqlite()) {
            if (! $this->indexExists($indexName)) {
                DB::statement(<<<SQL
CREATE UNIQUE INDEX `{$indexName}`
    ON `tax_graph_edges` (`scope`, `source`, `target`, `relationship`)
SQL);
            }

            return;
        }

        if ($this->indexExists($indexName)) {
            DB::statement("ALTER TABLE `tax_graph_edges` DROP INDEX `{$indexName}`");
        }

        DB::statement(<<<SQL
ALTER TABLE `tax_graph_edges`
    MODIFY `scope` VARCHAR(64) NOT NULL,
    MODIFY `source` VARCHAR(191) NOT NULL,
    MODIFY `target` VARCHAR(191) NOT NULL,
    MODIFY `relationship` VARCHAR(191) NOT NULL,
    MODIFY `risk_level` VARCHAR(50) NULL
SQL);

        if (! $this->indexExists($indexName)) {
            DB::statement(<<<SQL
CREATE UNIQUE INDEX `{$indexName}`
    ON `tax_graph_edges` (`scope`, `source`, `target`, `relationship`)
SQL);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tax_graph_edges')) {
            return;
        }

        $indexName = 'tax_graph_edges_scope_source_target_relationship_unique';

        if ($this->isSqlite()) {
            if ($this->indexExists($indexName)) {
                DB::statement("DROP INDEX `{$indexName}`");
            }

            return;
        }

        if ($this->indexExists($indexName)) {
            DB::statement("ALTER TABLE `tax_graph_edges` DROP INDEX `{$indexName}`");
        }

        DB::statement(<<<SQL
ALTER TABLE `tax_graph_edges`
    MODIFY `scope` VARCHAR(255) NOT NULL,
    MODIFY `source` VARCHAR(255) NOT NULL,
    MODIFY `target` VARCHAR(255) NOT NULL,
    MODIFY `relationship` VARCHAR(255) NOT NULL,
    MODIFY `risk_level` VARCHAR(255) NULL
SQL);
    }

    private function indexExists(string $indexName): bool
    {
        if ($this->isSqlite()) {
            return collect(DB::select("PRAGMA index_list('tax_graph_edges')"))
                ->contains(fn (object $index): bool => ($index->name ?? null) === $indexName);
        }

        $database = DB::getDatabaseName();

        return (bool) DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', 'tax_graph_edges')
            ->where('index_name', $indexName)
            ->exists();
    }

    private function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
};
