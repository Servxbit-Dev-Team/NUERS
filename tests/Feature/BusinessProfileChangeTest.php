<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use Illuminate\Support\Facades\{Crypt,DB};
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessProfileChangeTest extends TestCase
{
    private User $owner;
    private User $admin;
    private string $merchant;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite','database.connections.sqlite.database' => ':memory:','cache.default' => 'array','notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->owner = $this->actor('merchant');
        $this->admin = $this->actor('super_admin');
        $this->merchant = (string)Str::uuid();
        DB::table('merchants')->insert(['id' => $this->merchant,'merchant_account_id' => $this->owner->id,'business_name' => 'Synthetic Registry QA','owner_name' => 'Registered Representative','tin' => '123456789000','email' => 'registered@example.test','status' => 'active','business_type' => 'corporation','sector' => 'it','address' => 'Synthetic Address','city' => 'Caloocan City','region' => 'NCR','rdo_code' => '027','registry_revision' => 1]);
        $this->withToken($this->owner->api_token);
    }
    private function actor(string $role): User
    {
        $u = User::factory()->create(['name' => 'Original Google Display Name','api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id,'email' => $u->email,'role' => $role]);
        return $u;
    }
    private function payload(array $changes = ['owner_name' => 'Reviewed Representative']): array
    {
        return ['request_id' => (string)Str::uuid(),'changes' => $changes,'reason' => 'Supporting identity documents updated for review.'];
    }
    private function direct(): array
    {
        return ['revision' => 0,'registry_revision' => 1,'phone' => '+639171234567','website' => 'https://example.test','details' => ['business_description' => 'Synthetic contact update']];
    }
    private function decision(string $id, string $status = 'approved')
    {
        return $this->withToken($this->admin->api_token)->postJson('/api/admin/profile-changes/'.$id.'/review', ['decision' => $status,'evidence_confirmed' => true,'reason' => 'Independent verification completed for this request.']);
    }
    public function test_only_three_fields_are_direct_and_other_routes_cannot_bypass_approval(): void
    {
        DB::table('merchant_business_profiles')->insert(['merchant_id' => $this->merchant,'revision' => 0,'created_at' => now(),'updated_at' => now(),'encrypted_details' => Crypt::encryptString(json_encode(['trade_name' => 'Registered trade','representative_name' => 'Registered representative']))]);
        foreach ([['email' => 'different@example.test'],['owner_name' => 'Unaudited person'],['business_name' => 'Changed name'],['details' => ['trade_name' => 'Changed trade']],['details' => ['representative_name' => 'Changed rep']]] as $patch) {
            $this->putJson('/api/merchant/business-profile', array_replace($this->direct(), $patch))->assertUnprocessable();
        }
        $this->putJson('/api/business-accounts/current', ['business_name' => 'Synthetic Registry QA','owner_name' => 'Changed owner'])->assertUnprocessable();
        $this->putJson('/api/client/profile', ['full_name' => 'Bypass'])->assertForbidden();
        $this->patchJson('/api/tables/profiles', ['values' => ['full_name' => 'Bypass']])->assertForbidden();
        $this->putJson('/api/merchant/business-profile', $this->direct())->assertOk()->assertJsonPath('details.trade_name', 'Registered trade')->assertJsonPath('details.representative_name', 'Registered representative')->assertJsonPath('business.owner_name', 'Registered Representative')->assertJsonPath('business.email', 'registered@example.test')->assertJsonPath('business.phone', '+639171234567');
        $this->assertSame('Original Google Display Name', DB::table('users')->where('id', $this->owner->id)->value('name'));
    }
    public function test_owner_directory_uses_registered_identity_and_admin_edit_updates_it(): void
    {
        $this->getJson('/api/merchant/team')->assertOk()->assertJsonPath('members.data.0.name', 'Registered Representative');
        $form = ['revision' => 1,'reason' => 'Admin reviewed the representative evidence.','business_name' => 'Synthetic Registry QA','owner_name' => 'Admin Corrected Name','tin' => '123456789000','email' => 'registered@example.test','business_type' => 'corporation','sector' => 'it','address' => 'Synthetic Address','city' => 'Caloocan City','region' => 'NCR','rdo_code' => '027','vat_registered' => false];
        $this->withToken($this->admin->api_token)->putJson('/api/business-registry/'.$this->merchant, $form)->assertOk();
        $this->withToken($this->owner->api_token)->getJson('/api/merchant/team')->assertOk()->assertJsonPath('members.data.0.name', 'Admin Corrected Name');
    }
    public function test_pending_request_is_encrypted_idempotent_and_approval_is_atomic(): void
    {
        $p = $this->payload(['owner_name' => 'Approved Owner','business_name' => 'Approved Business','trade_name' => 'Approved Trade','representative_name' => 'Approved Representative']);
        $x = $this->postJson('/api/merchant/profile-changes', $p)->assertCreated()->assertJsonPath('status', 'pending')->json();
        $this->postJson('/api/merchant/profile-changes', $p)->assertCreated()->assertJsonPath('id', $x['id']);
        $this->postJson('/api/merchant/profile-changes', $this->payload())->assertConflict();
        $this->assertSame('Registered Representative', DB::table('merchants')->where('id', $this->merchant)->value('owner_name'));
        $this->assertStringNotContainsString('Approved Owner', DB::table('business_registry_requests')->where('id', $x['id'])->value('payload'));
        if (getenv('NUERS_QA_ARTIFACTS')) {
            \Illuminate\Support\Facades\File::ensureDirectoryExists(base_path('output/profile-approval'));
            $merchant = $this->getJson('/api/merchant/profile-changes')->json();
            $profile = $this->getJson('/api/merchant/business-profile')->json();
            $team = $this->getJson('/api/merchant/team')->json();
            $admin = $this->withToken($this->admin->api_token)->getJson('/api/admin/profile-changes')->json();
            file_put_contents(base_path('output/profile-approval/pending-fixture.json'), json_encode(compact('merchant', 'admin', 'profile', 'team'), JSON_PRETTY_PRINT));
        }
        $this->withToken($this->admin->api_token)->getJson('/api/admin/profile-changes')->assertOk()->assertJsonPath('total', 1);
        $this->postJson('/api/admin/profile-changes/'.$x['id'].'/review', ['decision' => 'approved','reason' => 'Evidence reviewed.'])->assertUnprocessable();
        $this->decision($x['id'])->assertOk()->assertJsonPath('status', 'approved');
        $this->decision($x['id'])->assertOk();
        $this->withToken($this->owner->api_token)->getJson('/api/merchant/business-profile')->assertOk()->assertJsonPath('business.owner_name', 'Approved Owner')->assertJsonPath('business.status', 'active')->assertJsonPath('details.trade_name', 'Approved Trade');
        $this->getJson('/api/merchant/team')->assertOk()->assertJsonPath('members.data.0.name', 'Approved Owner');
        $this->getJson('/api/merchant/profile-changes')->assertJsonPath('requests.data.0.status', 'approved');
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
        $this->assertDatabaseCount('merchant_transactions', 0);
        $this->assertDatabaseCount('merchant_wallet_entries', 0);
        if (getenv('NUERS_QA_ARTIFACTS')) {
            \Illuminate\Support\Facades\File::ensureDirectoryExists(base_path('output/profile-approval'));
            file_put_contents(base_path('output/profile-approval/approved-fixture.json'), json_encode(['merchant' => $this->getJson('/api/merchant/profile-changes')->json(),'profile' => $this->getJson('/api/merchant/business-profile')->json(),'team' => $this->getJson('/api/merchant/team')->json()], JSON_PRETTY_PRINT));
        }
    }
    public function test_roles_tenant_scope_withdrawal_and_rejection(): void
    {
        $x = $this->postJson('/api/merchant/profile-changes', $this->payload())->assertCreated()->json('id');
        $this->getJson('/api/admin/profile-changes')->assertForbidden();
        $this->postJson('/api/admin/profile-changes/'.$x.'/review', ['decision' => 'approved','reason' => 'Not authorized'])->assertForbidden();
        $other = $this->actor('merchant');
        $id = (string)Str::uuid();
        DB::table('merchants')->insert(['id' => $id,'merchant_account_id' => $other->id,'business_name' => 'Other QA','status' => 'active']);
        $this->withToken($other->api_token)->getJson('/api/merchant/profile-changes?merchant_id='.$this->merchant)->assertOk()->assertJsonPath('requests.total', 0);
        $this->postJson('/api/merchant/profile-changes/'.$x.'/withdraw')->assertNotFound();
        $this->withToken($this->owner->api_token)->postJson('/api/merchant/profile-changes/'.$x.'/withdraw')->assertOk();
        $this->decision($x)->assertConflict();
        $y = $this->withToken($this->owner->api_token)->postJson('/api/merchant/profile-changes', $this->payload())->assertCreated()->json('id');
        $this->decision($y, 'rejected')->assertOk();
        $this->assertSame('Registered Representative', DB::table('merchants')->where('id', $this->merchant)->value('owner_name'));
    }
    public function test_stale_identity_cannot_be_approved_but_contact_edits_do_not_block_review(): void
    {
        $id = $this->postJson('/api/merchant/profile-changes', $this->payload())->assertCreated()->json('id');
        $this->putJson('/api/merchant/business-profile', $this->direct())->assertOk();
        $this->decision($id)->assertOk();
        $id = $this->withToken($this->owner->api_token)->postJson('/api/merchant/profile-changes', $this->payload(['owner_name' => 'Another Proposed Name']))->assertCreated()->json('id');
        DB::table('merchants')->where('id', $this->merchant)->update(['owner_name' => 'Newer Admin Name']);
        $this->decision($id)->assertConflict();
        $this->assertSame('Newer Admin Name', DB::table('merchants')->where('id', $this->merchant)->value('owner_name'));
        $this->decision($id, 'rejected')->assertOk();
    }
    public function test_protected_flags_unknown_values_and_duplicate_tin_are_rejected(): void
    {
        foreach ([['status' => 'active'],['merchant_account_id' => '1'],['logo_url' => 'https://evil.test/x'],['tin' => '000000000000'],['owner_name' => '     '],['rdo_code' => '999']] as $changes) {
            $this->postJson('/api/merchant/profile-changes', $this->payload($changes))->assertUnprocessable();
        }
        DB::table('merchants')->insert(['id' => (string)Str::uuid(),'business_name' => 'Other','tin' => '999999999000']);
        $this->postJson('/api/merchant/profile-changes',$this->payload(['tin' => '999-999-999-000']))->assertUnprocessable();
        $this->assertDatabaseCount('business_registry_requests',0);
    }
}
