<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-process memo of Schema::hasTable() / hasColumn() for hot request paths.
 *
 * Each uncached call is a query against information_schema (about 1.5 ms on MySQL), and one merchant acceptance made
 * around 50 of them. Tables and columns that exist are remembered until flush() (migrations here are additive).
 * A "no" is remembered for NEGATIVE_TTL seconds only, so a table or column added by a migration is picked up by running
 * workers without a restart. flush() runs after every migration run (AppServiceProvider) and before every test.
 */
final class SchemaMap
{
    private const NEGATIVE_TTL = 60;

    /** @var array<string, array{0: bool, 1: int}> */
    private static array $seen = [];

    public static function hasTable(string $table): bool
    {
        return self::remember('t:'.$table, fn () => Schema::hasTable($table));
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return self::remember('c:'.$table.'.'.$column, fn () => Schema::hasColumn($table, $column));
    }

    public static function flush(): void
    {
        self::$seen = [];
    }

    private static function remember(string $key, callable $probe): bool
    {
        $key = DB::getDefaultConnection().'|'.$key;
        $now = now()->getTimestamp();
        if (isset(self::$seen[$key]) && (self::$seen[$key][0] || $now - self::$seen[$key][1] < self::NEGATIVE_TTL)) {
            return self::$seen[$key][0];
        }

        return (self::$seen[$key] = [$probe(), $now])[0];
    }
}
