<?php

namespace Tests\Unit;

use App\Support\SchemaMap;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaMapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
    }

    public function test_lookups_match_schema_and_repeat_calls_skip_the_database(): void
    {
        Schema::create('schema_map_probe', fn (Blueprint $t) => $t->string('known'));
        SchemaMap::flush();

        $this->assertTrue(SchemaMap::hasTable('schema_map_probe'));
        $this->assertTrue(SchemaMap::hasColumn('schema_map_probe', 'known'));
        $this->assertFalse(SchemaMap::hasColumn('schema_map_probe', 'missing'));
        $this->assertFalse(SchemaMap::hasTable('schema_map_absent'));

        DB::enableQueryLog();
        foreach (range(1, 5) as $_) {
            SchemaMap::hasTable('schema_map_probe');
            SchemaMap::hasColumn('schema_map_probe', 'known');
            SchemaMap::hasColumn('schema_map_probe', 'missing');
            SchemaMap::hasTable('schema_map_absent');
        }
        $this->assertCount(0, DB::getQueryLog(), 'Repeat lookups must not query the schema.');
    }

    public function test_an_existing_table_stays_known_until_flush_and_a_new_one_is_found_after_flush(): void
    {
        Schema::create('schema_map_a', fn (Blueprint $t) => $t->string('id'));
        SchemaMap::flush();
        $this->assertTrue(SchemaMap::hasTable('schema_map_a'));
        $this->assertFalse(SchemaMap::hasTable('schema_map_b'));

        Schema::create('schema_map_b', fn (Blueprint $t) => $t->string('id'));
        $this->assertFalse(SchemaMap::hasTable('schema_map_b'), 'A "no" is remembered briefly.');
        SchemaMap::flush();
        $this->assertTrue(SchemaMap::hasTable('schema_map_b'));
    }

    public function test_a_remembered_no_expires_so_running_workers_see_new_columns(): void
    {
        Schema::create('schema_map_c', fn (Blueprint $t) => $t->string('id'));
        SchemaMap::flush();
        $this->assertFalse(SchemaMap::hasColumn('schema_map_c', 'added_later'));

        Schema::table('schema_map_c', fn (Blueprint $t) => $t->string('added_later')->nullable());
        $this->assertFalse(SchemaMap::hasColumn('schema_map_c', 'added_later'));

        $this->travel(61)->seconds();
        $this->assertTrue(SchemaMap::hasColumn('schema_map_c', 'added_later'), 'After the negative TTL the lookup is repeated.');
    }
}
