<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BusinessRegistrationController;
use App\Models\User;
use App\Services\BirCompliance;
use App\Services\PlatformMail;
use App\Services\PlatformNotifications;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantTeamTest extends TestCase
{
    private User $owner;

    private User $other;

    private string $m;

    private string $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'notifications.dispatch_enabled' => false, 'app.url' => 'http://localhost:8001']);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        [$this->owner,$this->m] = $this->owner();
        [$this->other,$this->foreign] = $this->owner();
        $this->mock(PlatformMail::class, fn ($mock) => $mock->shouldReceive('settings')->andReturn(['enabled' => true]));
    }

    private function owner(): array
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => 'merchant']);
        $id = (string) Str::uuid();
        DB::table('merchants')->insert(['id' => $id, 'merchant_account_id' => $u->id, 'business_name' => 'Synthetic Team QA', 'tin' => '123456789', 'status' => 'active']);

        return [$u, $id];
    }

    private function roles(): array
    {
        return $this->withToken($this->owner->api_token)->getJson('/api/merchant/team')->assertOk()->json('roles');
    }

    private function invite(array $changes = []): array
    {
        $roles = $this->roles();
        $v = ['name' => 'Synthetic Teammate', 'email' => 'team-'.Str::random(8).'@example.test', 'role_id' => $roles[5]['id'], 'scope' => 'all', 'branch_ids' => [], 'terminal_scope' => 'all', 'terminal_ids' => [], 'reason' => 'Synthetic authorized access test'];
        $res = $this->postJson('/api/merchant/team/invitations', [...$v, ...$changes])->assertCreated();
        $row = DB::table('merchant_team_members')->where('id', $res->json('id'))->first();
        $job = DB::table('notification_outbox')->where('event', 'merchant.team.invitation')->orderByDesc('created_at')->first();
        $p = json_decode(Crypt::decryptString($job->encrypted_payload), true);
        parse_str(parse_url($p['action_url'], PHP_URL_FRAGMENT), $fragment);

        return [$row, $fragment['token'], $p];
    }

    private function accept(string $token): User
    {
        $this->withHeader('Authorization', '')->postJson('/api/team-invitations/accept', ['token' => $token, 'password' => 'SyntheticTeamPass123!', 'password_confirmation' => 'SyntheticTeamPass123!', 'accept_terms' => true, 'notice_version' => BusinessRegistrationController::NOTICE_VERSION])->assertOk();
        $member = DB::table('merchant_team_members')->whereNotNull('user_id')->orderByDesc('accepted_at')->first();
        $u = User::findOrFail($member->user_id);
        $u->api_token = Str::random(64);
        $u->save();

        return $u;
    }

    private function invoice(string $code, ?string $terminal = null, ?string $merchant = null): string
    {
        $id = (string) Str::uuid();
        DB::table('business_invoices')->insert(['id' => $id, 'merchant_id' => $merchant ?? $this->m, 'invoice_number' => 'SYN-'.Str::random(8), 'document_type' => 'vat_invoice', 'vat_registered' => true, 'status' => 'issued', 'merchant_name' => 'Synthetic seller', 'merchant_tin' => '123456789', 'merchant_address' => 'Synthetic address', 'seller_branch_code' => $code, 'buyer_name' => 'Synthetic buyer', 'issue_date' => '2026-10-16', 'total_amount' => 112, 'subtotal_amount' => 100, 'taxable_amount' => 100, 'vat_amount' => 12, 'issuer_terminal_id' => $terminal, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function branch(string $merchant, string $code): string
    {
        $id = (string) Str::uuid();
        DB::table('merchant_branches')->insert(['id' => $id, 'merchant_id' => $merchant, 'code' => 'BR-'.$code, 'bir_branch_code' => $code, 'name' => 'Synthetic '.$code, 'address' => 'Synthetic address', 'city' => 'QA', 'region' => 'QA', 'rdo_code' => '044', 'branch_type' => 'branch', 'status' => 'active', 'contact_encrypted' => Crypt::encryptString('{}'), 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_owner_directory_is_real_read_only_and_does_not_seed_accounts_or_claim_mfa(): void
    {
        $before = DB::table('users')->count();
        $this->roles();
        $this->assertDatabaseCount('users', $before);
        $this->assertDatabaseCount('merchant_team_roles', 0);
        $this->getJson('/api/merchant/team')->assertJsonPath('summary.total', 1)->assertJsonPath('summary.mfa', 0)->assertJsonPath('members.data.0.protected', true);
        $this->withToken($this->other->api_token)->getJson('/api/merchant/team')->assertJsonPath('summary.total', 1);
    }

    public function test_invite_is_encrypted_single_use_and_does_not_create_a_merchant_or_global_privilege(): void
    {
        [$x,$token,$payload] = $this->invite();
        $this->assertStringNotContainsString('example.test', $x->profile_encrypted);
        $this->assertStringNotContainsString($token, json_encode($x));
        $this->assertDatabaseCount('users', 2);
        $this->assertSame('NUERS', app(PlatformMail::class)->settings()['from_name'] ?? 'NUERS');
        $this->assertStringStartsWith('http://localhost:8001/accept-team-invite#', $payload['action_url']);
        $u = $this->accept($token);
        $this->assertSame('merchant', DB::table('profiles')->where('id', $u->id)->value('role'));
        $this->assertNotNull($u->email_verified_at);
        $this->assertDatabaseCount('merchants', 2);
        $this->postJson('/api/team-invitations/accept', ['token' => $token, 'password' => 'SyntheticTeamPass123!', 'password_confirmation' => 'SyntheticTeamPass123!', 'accept_terms' => true, 'notice_version' => BusinessRegistrationController::NOTICE_VERSION])->assertUnprocessable();
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }

    public function test_staff_cannot_reach_owner_apis_or_cross_tenant_even_through_legacy_routes(): void
    {
        [$x,$token] = $this->invite();
        $u = $this->accept($token);
        $this->withToken($u->api_token)->getJson('/api/merchant/access')->assertOk()->assertJsonPath('owner', false)->assertJsonPath('merchant.id', $this->m);
        foreach (['/api/merchant/team', '/api/merchant/overview', '/api/tables/merchants', '/api/merchant/wallet', '/api/merchant/branches', '/api/admin/wallets', '/api/merchant/api-keys', '/api/merchant/customers'] as $url) {
            $this->getJson($url)->assertForbidden();
        }$this->postJson('/api/business-invoices', [])->assertForbidden();
        $this->postJson('/api/document-copies/invoice/nope/email', [])->assertForbidden();
        $own = $this->invoice('00000');
        $foreign = $this->invoice('00000', null, $this->foreign);
        $this->getJson('/api/merchant/records')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.document_id', $own);
        $this->getJson('/api/merchant/records/invoice:'.$foreign)->assertNotFound();
        $this->getJson('/api/document-copies/invoice/'.$foreign)->assertNotFound();
    }

    public function test_branch_scope_filters_rows_summaries_options_details_and_exports(): void
    {
        $b = $this->branch($this->m, '00001');
        $c = $this->branch($this->foreign, '00002');
        $roles = $this->roles();
        [$x,$token] = $this->invite(['role_id' => $roles[1]['id'], 'scope' => 'branches', 'branch_ids' => [$b]]);
        $u = $this->accept($token);
        $allowed = $this->invoice('00001');
        $denied = $this->invoice('00002');
        $missing = $this->invoice('');
        $this->withToken($u->api_token)->getJson('/api/merchant/records')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.document_id', $allowed)->assertJsonCount(1, 'options.branches');
        $this->getJson('/api/merchant/records?branch=00002')->assertJsonCount(0, 'data');
        $this->getJson('/api/merchant/records/invoice:'.$denied)->assertNotFound();
        $this->getJson('/api/document-copies/invoice/'.$denied)->assertNotFound();
        $this->getJson('/api/business-invoices/'.$allowed)->assertForbidden();
        $this->getJson('/api/merchant/records/export')->assertOk()->assertDontSee($denied);
        $this->withToken($this->owner->api_token)->postJson('/api/merchant/team/invitations', ['name' => 'Bad scope', 'email' => 'bad@example.test', 'role_id' => $roles[1]['id'], 'scope' => 'branches', 'branch_ids' => [$c], 'terminal_scope' => 'all', 'terminal_ids' => [], 'reason' => 'Synthetic tenant scope failure'])->assertUnprocessable();
    }

    public function test_terminal_scope_never_infers_historical_terminal_and_honors_inactive_terminals(): void
    {
        $b = $this->branch($this->m, '00001');
        $t = (string) Str::uuid();
        DB::table('merchant_terminals')->insert(['id' => $t, 'merchant_id' => $this->m, 'branch_id' => $b, 'code' => 'TRM-1', 'identifier' => 'SYN-1', 'name' => 'Synthetic terminal', 'pos_type' => 'pos', 'status' => 'active', 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now()]);
        [$x,$token] = $this->invite(['scope' => 'branches', 'branch_ids' => [$b], 'terminal_scope' => 'terminals', 'terminal_ids' => [$t]]);
        $u = $this->accept($token);
        $id = $this->invoice('00001', $t);
        $this->invoice('00001');
        $this->withToken($u->api_token)->getJson('/api/merchant/records')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.document_id', $id);
        DB::table('merchant_terminals')->where('id', $t)->update(['status' => 'inactive']);
        $this->getJson('/api/merchant/records')->assertJsonCount(0, 'data');
    }

    public function test_revision_and_scope_ceiling_and_immediate_session_revocation(): void
    {
        [$x,$token] = $this->invite();
        $u = $this->accept($token);
        $member = DB::table('merchant_team_members')->where('id', $x->id)->first();
        $v = ['revision' => $member->revision, 'action' => 'suspend', 'reason' => 'Synthetic access suspension review'];
        $this->withToken($this->other->api_token)->patchJson('/api/merchant/team/members/'.$x->id, $v)->assertNotFound();
        $this->withToken($this->owner->api_token)->patchJson('/api/merchant/team/members/'.$x->id, [...$v, 'revision' => 999])->assertConflict();
        $this->patchJson('/api/merchant/team/members/'.$x->id, $v)->assertOk();
        $this->withToken($u->api_token)->getJson('/api/merchant/access')->assertUnauthorized();
        $fresh = User::find($u->id);
        $fresh->api_token = Str::random(64);
        $fresh->save();
        $this->withToken($fresh->api_token)->getJson('/api/merchant/records')->assertForbidden();
    }

    public function test_privileged_access_changes_and_staff_operations_require_real_mfa(): void
    {
        config(['security.enforce_privileged_mfa' => true]);
        $this->withToken($this->owner->api_token)->postJson('/api/merchant/team/roles', [])->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        DB::table('users')->where('id', $this->owner->id)->update(['mfa_secret' => 'synthetic-test-only', 'api_authenticated_at' => now()->subHour(), 'api_mfa_verified_at' => now()->subMinutes(40)]);
        $this->postJson('/api/merchant/team/roles', [])->assertUnprocessable();
        config(['security.enforce_privileged_mfa' => false]);
        [$x,$token] = $this->invite();
        $u = $this->accept($token);
        config(['security.enforce_privileged_mfa' => true]);
        $this->withToken($u->api_token)->getJson('/api/merchant/records')->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        $this->getJson('/api/business-accounts/current')->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        $this->getJson('/api/auth/mfa/status')->assertOk();
        DB::table('users')->where('id', $u->id)->update(['mfa_secret' => 'synthetic-test-only', 'api_mfa_verified_at' => now()]);
        $this->travel(16)->minutes();
        $this->getJson('/api/merchant/records')->assertOk();
        $this->getJson('/api/business-accounts/current')->assertOk();
        $this->getJson('/api/auth/session')->assertOk()->assertJsonPath('session.mfa_required', false);
    }

    public function test_expiry_cancel_resend_password_policy_and_invalid_tokens_fail_closed(): void
    {
        [$x,$token] = $this->invite();
        $this->withHeader('Authorization', '')->postJson('/api/team-invitations/accept', ['token' => $token, 'password' => 'short1', 'password_confirmation' => 'short1', 'accept_terms' => true, 'notice_version' => BusinessRegistrationController::NOTICE_VERSION])->assertUnprocessable();
        $this->assertDatabaseCount('users', 2);
        $this->travel(73)->hours();
        $this->owner->api_token = Str::random(64);
        $this->owner->save();
        $this->postJson('/api/team-invitations/preview', ['token' => $token])->assertUnprocessable();
        $this->withToken($this->owner->api_token)->patchJson('/api/merchant/team/members/'.$x->id, ['revision' => 1, 'action' => 'resend', 'reason' => 'Synthetic renewal of expired invitation'])->assertOk();
        $this->withHeader('Authorization', '')->postJson('/api/team-invitations/preview', ['token' => $token])->assertUnprocessable();
        $this->withToken($this->owner->api_token)->patchJson('/api/merchant/team/members/'.$x->id, ['revision' => 2, 'action' => 'revoke', 'reason' => 'Synthetic cancelled invitation review'])->assertOk();
        $row = DB::table('merchant_team_members')->where('id', $x->id)->first();
        $this->assertNull($row->invite_hash);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_role_permissions_cannot_cross_platform_ceiling_or_expand_scoped_users(): void
    {
        $b = $this->branch($this->m, '00001');
        [$x] = $this->invite(['scope' => 'branches', 'branch_ids' => [$b]]);
        $role = $this->roles()[5];
        $v = ['name' => $role['name'], 'description' => 'Synthetic policy', 'color' => 'cyan', 'permissions' => ['documents.view', 'wallet.view'], 'revision' => 1, 'reason' => 'Synthetic role scope ceiling review'];
        $this->putJson('/api/merchant/team/roles/'.$role['id'], $v)->assertUnprocessable();
        $this->putJson('/api/merchant/team/roles/'.$role['id'], [...$v, 'permissions' => ['super_admin']])->assertUnprocessable();
        $this->putJson('/api/merchant/team/roles/'.$role['id'], [...$v, 'permissions' => ['documents.export']])->assertUnprocessable();
        $this->putJson('/api/merchant/team/roles/'.$role['id'], [...$v, 'permissions' => ['documents.view', 'documents.export']])->assertOk();
        $this->putJson('/api/merchant/team/roles/'.$role['id'], [...$v, 'permissions' => ['documents.view']])->assertConflict();
    }

    public function test_owner_is_not_a_mutable_team_member_and_export_is_formula_safe(): void
    {
        [$x] = $this->invite(['name' => '=HYPERLINK("bad")']);
        $this->patchJson('/api/merchant/team/members/'.(string) $this->owner->id, ['revision' => 1, 'action' => 'revoke', 'reason' => 'Synthetic owner lockout attempt'])->assertNotFound();
        $this->assertNotNull(User::find($this->owner->id)->api_token_hash);
        $res = $this->get('/api/merchant/team/export?reason=Synthetic%20authorized%20audit%20export')->assertOk();
        $this->assertStringContainsString("'=HYPERLINK", $res->streamedContent());
    }

    public function test_business_wide_operations_remain_tenant_bound_and_financial_authority_stays_blocked(): void
    {
        $roles = $this->roles();
        [$member,$token] = $this->invite(['role_id' => $roles[0]['id']]);
        $staff = $this->accept($token);
        $this->invoice('00000');
        $this->invoice('00000', null, $this->foreign);
        $this->withToken($staff->api_token)->getJson('/api/business-invoices')->assertOk();
        $this->getJson('/api/merchant/customers')->assertOk();
        $this->getJson('/api/merchant/wallet')->assertOk()->assertJsonPath('merchant.id', $this->m);
        $this->getJson('/api/merchant/api-keys')->assertOk();
        $this->postJson('/api/merchant/api-keys', [])->assertForbidden();
        $this->postJson('/api/merchant/wallet/topups', [])->assertForbidden();
        $this->postJson('/api/eis-readiness/coverage', [])->assertForbidden();
        $this->putJson('/api/business-accounts/current', [])->assertForbidden();
    }

    public function test_no_work_invite_can_convert_an_existing_privileged_or_personal_account(): void
    {
        $roles = $this->roles();
        $this->postJson('/api/merchant/team/invitations', ['name' => 'Owner hijack', 'email' => $this->other->email, 'role_id' => $roles[5]['id'], 'scope' => 'all', 'branch_ids' => [], 'terminal_scope' => 'all', 'terminal_ids' => [], 'reason' => 'Synthetic existing account rejection'])->assertUnprocessable();
        $this->assertDatabaseCount('merchant_team_members', 0);
        $this->getJson('/api/merchant/team?search=%27%20OR%201%3D1--')->assertOk()->assertJsonPath('members.total', 0);
    }

    public function test_cancelled_invitation_is_discarded_by_worker_before_any_smtp_call(): void
    {
        [$row] = $this->invite();
        $this->patchJson('/api/merchant/team/members/'.$row->id, ['revision' => 1, 'action' => 'revoke', 'reason' => 'Synthetic cancelled mail delivery'])->assertOk();
        config(['notifications.dispatch_enabled' => true]);
        $this->assertTrue(app(PlatformNotifications::class)->runOne());
        $this->assertSame('cancelled', DB::table('notification_outbox')->where('event', 'merchant.team.invitation')->value('state'));
        $this->assertDatabaseCount('users', 2);
    }

    public function test_owner_password_reset_queues_secure_recovery_without_setting_password_or_removing_mfa(): void
    {
        [$row,$token] = $this->invite();
        $staff = $this->accept($token);
        $password = $staff->password;
        $this->withToken($this->owner->api_token)->patchJson('/api/merchant/team/members/'.$row->id, ['revision' => 2, 'action' => 'reset_password', 'reason' => 'Synthetic user requested work account recovery'])->assertOk();
        $this->assertSame($password, User::find($staff->id)->password);
        $reset = DB::table('account_password_resets')->where('user_id', $staff->id)->first();
        $this->assertNotNull($reset);
        $job = DB::table('notification_outbox')->where('event', 'security.password.reset_link')->first();
        $this->assertSame('security', $job->category);
        $this->assertSame('queued', $job->state);
        $this->withToken($staff->api_token)->getJson('/api/merchant/records/export')->assertForbidden();
        $this->getJson('/api/document-copies/invoice/synthetic/pdf')->assertForbidden();
    }

    public function test_merchant_owner_cannot_reset_or_revoke_a_promoted_platform_identity(): void
    {
        [$row,$token] = $this->invite();
        $staff = $this->accept($token);
        $hash = User::find($staff->id)->api_token_hash;
        DB::table('profiles')->where('id', $staff->id)->update(['role' => 'super_admin']);
        foreach (['reset_password', 'revoke_sessions'] as $action) {
            $this->withToken($this->owner->api_token)->patchJson('/api/merchant/team/members/'.$row->id, ['revision' => 2, 'action' => $action, 'reason' => 'Synthetic platform identity protection'])->assertConflict();
        }
        $this->assertDatabaseCount('account_password_resets', 0);
        $this->assertSame($hash, User::find($staff->id)->api_token_hash);
        $this->patchJson('/api/merchant/team/members/'.$row->id, ['revision' => 2, 'action' => 'revoke', 'reason' => 'Synthetic merchant membership offboarding'])->assertOk();
        $this->assertSame($hash, User::find($staff->id)->api_token_hash);
        $this->assertSame('super_admin', DB::table('profiles')->where('id', $staff->id)->value('role'));
    }

    public function test_registering_owner_is_automatically_projected_as_merchant_admin_in_directory_filters_and_access(): void
    {
        $roles = $this->roles();
        $admin = collect($roles)->firstWhere('name', 'Merchant Admin');
        $this->getJson('/api/merchant/team')->assertOk()->assertJsonPath('members.data.0.email', $this->owner->email)->assertJsonPath('members.data.0.role_id', $admin['id'])->assertJsonPath('members.data.0.protected', true);
        $this->getJson('/api/merchant/team?role='.$admin['id'])->assertOk()->assertJsonPath('members.total', 1)->assertJsonPath('members.data.0.user_id', (string) $this->owner->id);
        $this->getJson('/api/merchant/team?search=Merchant%20Admin')->assertOk()->assertJsonPath('members.total', 1);
        $directory = $this->getJson('/api/merchant/team')->json();
        $this->assertSame(1, collect($directory['roles'])->firstWhere('name', 'Merchant Admin')['user_count']);
        $this->getJson('/api/merchant/access')->assertOk()->assertJsonPath('owner', true)->assertJsonPath('role', 'Merchant Admin');
        $this->assertDatabaseCount('merchant_team_members', 0);
        $this->assertDatabaseCount('merchant_team_roles', 0);
        $this->withToken($this->other->api_token)->getJson('/api/merchant/team?search='.$this->owner->email)->assertOk()->assertJsonPath('members.total', 0);
    }

    public function test_custom_roles_cannot_hide_the_protected_owner_merchant_admin_projection(): void
    {
        $role = (string) Str::uuid();
        DB::table('merchant_team_roles')->insert(['id' => $role, 'merchant_id' => $this->m, 'name' => 'Custom Viewer', 'description' => 'Synthetic custom role', 'color' => 'blue', 'permissions' => '["documents.view"]', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $state = $this->withToken($this->owner->api_token)->getJson('/api/merchant/team')->assertOk()->json();
        $admin = collect($state['roles'])->firstWhere('name', 'Merchant Admin');
        $this->assertTrue($admin['owner_only']);
        $this->assertSame(1, $admin['user_count']);
        $this->assertSame($admin['id'], $state['members']['data'][0]['role_id']);
        $this->getJson('/api/merchant/team?role='.$admin['id'])->assertOk()->assertJsonPath('members.total', 1);
        $this->assertDatabaseCount('merchant_team_roles',1);
    }
}
