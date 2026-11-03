<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Role x table x method guarantees for the generic /api/tables/* endpoint. */
class TableEndpointSecurityTest extends TestCase
{
    private const TABLES = ['profiles', 'client_accounts', 'merchants', 'notifications', 'merchant_transactions', 'transaction_receipts'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $file) {
            (require $file)->up();
        }
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['api_token' => fake()->uuid()]);
        DB::table('profiles')->insert(['id' => $user->id, 'email' => $user->email, 'role' => $role]);

        return $user;
    }

    private function eq(array $filter): string
    {
        return '?'.http_build_query(['eq' => json_encode($filter)]);
    }

    public function test_every_table_requires_a_session(): void
    {
        foreach (self::TABLES as $table) {
            foreach (['getJson', 'patchJson', 'postJson', 'deleteJson'] as $verb) {
                $this->$verb('/api/tables/'.$table)->assertUnauthorized();
            }
        }
    }

    public function test_create_and_delete_are_refused_for_every_role_and_table(): void
    {
        $before = DB::table('profiles')->count();
        foreach (['super_admin', 'admin', 'bir', 'merchant', 'client', 'consumer'] as $role) {
            $user = $this->actor($role);
            $before++;
            foreach (self::TABLES as $table) {
                $this->withToken($user->api_token)->postJson('/api/tables/'.$table, ['values' => ['role' => 'super_admin']])->assertStatus(409);
                $this->withToken($user->api_token)->deleteJson('/api/tables/'.$table)->assertStatus(409);
            }
        }
        $this->assertSame($before, DB::table('profiles')->count());
    }

    public function test_an_administrator_cannot_grant_or_remove_super_administrator_access(): void
    {
        $admin = $this->actor('admin');
        $super = $this->actor('super_admin');
        $this->withToken($admin->api_token)->patchJson('/api/tables/profiles'.$this->eq(['id' => $admin->id]), ['values' => ['role' => 'super_admin']])->assertForbidden();
        $this->withToken($admin->api_token)->patchJson('/api/tables/profiles'.$this->eq(['id' => $super->id]), ['values' => ['role' => 'client']])->assertForbidden();
        $this->withToken($admin->api_token)->patchJson('/api/tables/profiles'.$this->eq(['id' => $super->id]), ['values' => ['full_name' => 'Renamed']])->assertForbidden();
        $this->assertSame('admin', DB::table('profiles')->where('id', $admin->id)->value('role'));
        $this->assertSame('super_admin', DB::table('profiles')->where('id', $super->id)->value('role'));

        $client = $this->actor('client');
        $this->withToken($super->api_token)->patchJson('/api/tables/profiles'.$this->eq(['id' => $client->id]), ['values' => ['role' => 'super_admin']])->assertOk();
        $this->assertSame('super_admin', DB::table('profiles')->where('id', $client->id)->value('role'));
    }

    public function test_bulk_changes_must_name_their_records_and_leave_the_audit_trail_with_targets(): void
    {
        $admin = $this->actor('admin');
        $target = $this->actor('client');
        $this->withToken($admin->api_token)->patchJson('/api/tables/profiles', ['values' => ['role' => 'merchant']])->assertStatus(422);
        $this->withToken($admin->api_token)->patchJson('/api/tables/client_accounts', ['values' => ['status' => 'suspended']])->assertStatus(422);
        $this->assertSame('client', DB::table('profiles')->where('id', $target->id)->value('role'));

        $this->withToken($admin->api_token)->patchJson('/api/tables/profiles'.$this->eq(['id' => $target->id]), ['values' => ['role' => 'merchant']])->assertOk();
        $details = json_decode(DB::table('eis_audit_events')->where('event', 'account.table.updated')->latest('id')->value('details'), true);
        $this->assertSame([$target->id], $details['target_ids']);
        $this->assertSame('merchant', $details['role_to']);
    }

    public function test_sign_in_email_and_identity_cannot_be_rewritten_but_unchanged_values_are_accepted(): void
    {
        $admin = $this->actor('admin');
        $target = $this->actor('merchant');
        $url = '/api/tables/profiles'.$this->eq(['id' => $target->id]);
        $this->withToken($admin->api_token)->patchJson($url, ['values' => ['email' => 'attacker@example.test']])->assertForbidden();
        $this->withToken($admin->api_token)->patchJson($url, ['values' => ['id' => 987654]])->assertForbidden();
        $this->withToken($admin->api_token)->patchJson($url, ['values' => ['email' => $target->email, 'full_name' => 'Same email']])->assertOk();
        $this->assertSame($target->email, DB::table('profiles')->where('id', $target->id)->value('email'));
    }

    public function test_malformed_values_are_rejected_not_crashed(): void
    {
        $admin = $this->actor('admin');
        $this->withToken($admin->api_token)->patchJson('/api/tables/profiles'.$this->eq(['id' => $admin->id]), ['values' => 'x'])->assertStatus(422);
        $this->withToken($admin->api_token)->patchJson('/api/tables/profiles'.$this->eq(['id' => $admin->id]), ['values' => ['full_name' => ['a']]])->assertStatus(422);
    }

    public function test_merchants_cannot_read_or_probe_internal_audit_columns_and_cannot_see_other_businesses(): void
    {
        $one = $this->actor('merchant');
        $two = $this->actor('merchant');
        foreach ([[$one, 'm1'], [$two, 'm2']] as [$owner, $id]) {
            DB::table('merchants')->insert(['id' => $id, 'business_name' => 'Biz '.$id, 'tin' => '1', 'merchant_account_id' => $owner->id, 'notes' => 'internal '.$id, 'compliance_score' => 7, 'next_audit_date' => '2027-01-01']);
        }
        $rows = $this->withToken($one->api_token)->getJson('/api/tables/merchants')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('m1', $rows[0]['id']);
        foreach (['notes', 'compliance_score', 'last_audit_date', 'next_audit_date'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $rows[0]);
            $this->withToken($one->api_token)->getJson('/api/tables/merchants'.$this->eq([$hidden => 'x']))->assertStatus(422);
        }
        $this->withToken($one->api_token)->getJson('/api/tables/merchants'.$this->eq(['id' => 'm2']))->assertOk()->assertJsonCount(0, 'data');

        $admin = $this->actor('admin');
        $this->withToken($admin->api_token)->getJson('/api/tables/merchants'.$this->eq(['id' => 'm1']))->assertJsonPath('data.0.notes', 'internal m1');
    }

    public function test_clients_and_consumers_only_reach_their_own_rows(): void
    {
        $a = $this->actor('client');
        $b = $this->actor('client');
        DB::table('client_accounts')->insert([['id' => 'ca', 'account_number' => 'A1', 'full_name' => 'A', 'user_id' => $a->id, 'email' => 'a@example.test', 'status' => 'active'], ['id' => 'cb', 'account_number' => 'A2', 'full_name' => 'B', 'user_id' => $b->id, 'email' => 'b@example.test', 'status' => 'active']]);
        $this->withToken($a->api_token)->getJson('/api/tables/client_accounts')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'ca');
        $this->withToken($a->api_token)->getJson('/api/tables/client_accounts'.$this->eq(['id' => 'cb']))->assertJsonCount(0, 'data');
        $this->withToken($a->api_token)->getJson('/api/tables/profiles')->assertJsonCount(1, 'data');
        $this->withToken($a->api_token)->getJson('/api/tables/merchants')->assertForbidden();
        $this->withToken($a->api_token)->getJson('/api/tables/notifications')->assertForbidden();
        $this->withToken($a->api_token)->patchJson('/api/tables/client_accounts', ['values' => ['wallet_balance' => 99999]])->assertForbidden();
        $this->withToken($a->api_token)->patchJson('/api/tables/client_accounts', ['values' => ['status' => 'active']])->assertForbidden();
        $this->withToken($a->api_token)->patchJson('/api/tables/client_accounts', ['values' => ['mobile' => '09170000000']])->assertOk()->assertJsonPath('count', 1);
        $this->assertNull(DB::table('client_accounts')->where('id', 'cb')->value('mobile'));
    }

    public function test_merchants_cannot_change_profiles_or_issued_financial_records(): void
    {
        $m = $this->actor('merchant');
        $this->withToken($m->api_token)->patchJson('/api/tables/profiles', ['values' => ['full_name' => 'x']])->assertForbidden();
        foreach (['merchant_transactions', 'transaction_receipts', 'merchants'] as $table) {
            $this->withToken($m->api_token)->patchJson('/api/tables/'.$table, ['values' => ['status' => 'suspended']])->assertStatus(409);
        }
    }
}
