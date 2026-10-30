<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seeding_does_not_provision_demo_accounts_or_financial_records(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['users', 'merchants', 'merchant_transactions', 'transaction_receipts'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "Unexpected default data in {$table}");
        }
    }
}
