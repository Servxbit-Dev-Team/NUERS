<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EnterpriseDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.timezone' => 'UTC']);
        DB::purge('sqlite');
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        (require database_path('migrations/2026_06_02_000001_create_nuers_tables.php'))->up();
        (require database_path('migrations/2026_10_17_000010_merchant_wallets.php'))->up();
        Schema::table('profiles', fn (Blueprint $t) => $t->string('tin')->nullable());
        Schema::create('client_accounts', function (Blueprint $t) {
            $t->string('id')->primary(); $t->unsignedBigInteger('user_id')->nullable();
            $t->string('email')->nullable(); $t->string('tin')->nullable();
            $t->string('status')->default('active'); $t->decimal('wallet_balance')->default(0);
        });
        Schema::create('eis_transmissions', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('merchant_id')->nullable(); $t->string('status');
            $t->string('environment')->default('certification'); $t->timestamp('submitted_at')->nullable(); $t->string('bir_reference_number')->nullable();
        });
        Schema::create('eis_acknowledgements', function (Blueprint $t) { $t->string('id'); $t->string('transmission_id'); });
        Schema::create('system_health_checks', function (Blueprint $t) {
            $t->string('service_key'); $t->string('category'); $t->string('service_name');
            $t->string('status'); $t->timestamp('checked_at')->nullable();
        });
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
    }

    private function user(string $role, ?string $tin = null): User
    {
        $user = User::factory()->create(['api_token' => fake()->uuid()]);
        DB::table('profiles')->insert(['id' => $user->id, 'email' => $user->email, 'role' => $role, 'tin' => $tin]);
        return $user;
    }

    private function transaction(string $id, string $merchant, ?string $tin, string $status = 'completed', string $date = '2026-10-15 03:00:00'): void
    {
        DB::table('merchant_transactions')->insert(['id' => $id, 'merchant_id' => $merchant, 'customer_tin' => $tin, 'status' => $status, 'amount' => 100, 'created_at' => $date, 'region' => 'NCR']);
        DB::table('transaction_receipts')->insert(['id' => 'r-'.$id, 'transaction_id' => $id, 'merchant_id' => $merchant, 'buyer_tin' => $tin, 'receipt_number' => $id, 'merchant_name' => $merchant, 'merchant_tin' => '111222333', 'status' => 'issued']);
    }

    public function test_sessions_and_portal_roles_are_required(): void
    {
        $this->getJson('/api/enterprise-dashboards/admin')->assertUnauthorized();
        $user = $this->user('client');
        $this->withToken($user->api_token)->getJson('/api/enterprise-dashboards/admin')->assertForbidden();
        $this->withToken($user->api_token)->getJson('/api/enterprise-dashboards/merchant')->assertForbidden();
    }

    public function test_merchant_totals_receipts_regions_and_recent_rows_are_account_scoped(): void
    {
        $user = $this->user('merchant');
        DB::table('merchants')->insert(['id' => 'own', 'business_name' => 'Owned business', 'merchant_account_id' => $user->id]);
        $this->transaction('own-transaction', 'own', '123456789');
        $this->transaction('other-transaction', 'other', '987654321');
        DB::table('eis_transmissions')->insert([['id' => 'own-submission', 'merchant_id' => 'own', 'status' => 'accepted', 'environment' => 'production', 'submitted_at' => now(), 'bir_reference_number' => 'ACTUAL-FIXTURE'], ['id' => 'other-submission', 'merchant_id' => 'other', 'status' => 'accepted', 'environment' => 'certification', 'submitted_at' => null, 'bir_reference_number' => null]]);
        DB::table('eis_acknowledgements')->insert(['id' => 'ack-own', 'transmission_id' => 'own-submission']);
        $response = $this->withToken($user->api_token)->getJson('/api/enterprise-dashboards/merchant');
        $response->assertOk()->assertJsonPath('metrics.0.value', 1)->assertJsonPath('metrics.1.value', 1)
            ->assertJsonPath('metrics.4.value', 0)->assertJsonPath('recent.0.id', 'own-transaction')
            ->assertJsonCount(1, 'recent')->assertJsonPath('regions.0.count', 1)->assertJsonPath('notification_count', null);
        $this->assertSame(1, array_sum(array_column($response->json('volume'), 'valid')));
        $this->assertStringNotContainsString('other-transaction', $response->getContent());
    }

    public function test_citizen_tin_scope_accepts_formatting_and_never_exposes_other_records(): void
    {
        $user = $this->user('client', '123-456-789');
        DB::table('client_accounts')->insert(['id' => 'own', 'user_id' => $user->id, 'wallet_balance' => 150, 'tin'=>'123456789', 'status'=>'active']);
        $this->transaction('mine', 'store', '123456789');
        $this->transaction('not-mine', 'store', '987654321');
        $response = $this->withToken($user->api_token)->getJson('/api/enterprise-dashboards/citizen');
        $response->assertOk()->assertJsonPath('metrics.0.value', 1)->assertJsonPath('metrics.1.value', 1)
            ->assertJsonPath('metrics.4.value', 150)->assertJsonCount(1, 'recent')->assertJsonPath('recent.0.id', 'mine');
        $this->assertStringNotContainsString('not-mine', $response->getContent());
    }

    public function test_unlinked_accounts_return_empty_records_instead_of_global_totals(): void
    {
        $this->transaction('private', 'store', '123456789');
        foreach (['client' => 'citizen', 'merchant' => 'merchant'] as $role => $portal) {
            $user = $this->user($role);
            $this->withToken($user->api_token)->getJson('/api/enterprise-dashboards/'.$portal)
                ->assertOk()->assertJsonPath('metrics.0.value', 0)->assertJsonPath('metrics.1.value', 0)->assertJsonCount(0, 'recent');
        }
    }

    public function test_admin_period_counts_respect_manila_midnight_and_pending_status(): void
    {
        $user = $this->user('admin');
        $this->transaction('yesterday', 'store', null, 'completed', '2026-10-14 15:59:00');
        $this->transaction('today', 'store', null, 'completed', '2026-10-14 16:01:00');
        $this->transaction('failed', 'store', null, 'failed');
        $this->transaction('pending', 'store', null, 'pending');
        $response = $this->withToken($user->api_token)->getJson('/api/enterprise-dashboards/admin');
        $response->assertOk()->assertJsonPath('metrics.0.value', 4)->assertJsonPath('metrics.2.value', 1);
        $this->assertSame(1, array_sum(array_column($response->json('volume'), 'valid')));
        $this->assertSame(1, array_sum(array_column($response->json('volume'), 'invalid')));
        $this->assertSame(1, array_sum(array_column($response->json('volume'), 'pending')));
        $this->withToken($user->api_token)->getJson('/api/enterprise-dashboards/admin?period=invalid')->assertStatus(422);
    }

    public function test_registered_merchants_counts_registry_businesses_not_citizen_or_profile_tins(): void
    {
        $user = $this->user('admin');
        DB::table('merchants')->insert(['id' => 'store', 'business_name' => 'Store', 'tin' => '123-456-789-000']);
        DB::table('client_accounts')->insert(['id' => 'client', 'tin' => '999888777']);
        $this->user('client', '888777666');
        $this->withToken($user->api_token)->getJson('/api/enterprise-dashboards/admin')->assertOk()
            ->assertJsonPath('metrics.3.label', 'Registered Merchants')->assertJsonPath('metrics.3.value', 1);
    }
}
