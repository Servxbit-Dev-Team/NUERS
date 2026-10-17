<?php

namespace Tests\Feature;

use App\Mail\RegistrationCodeMail;
use App\Services\RegistrationVerification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'mail.default' => 'smtp', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Mail::fake();
        foreach (['0001_01_01_000000_create_users_table.php', '2026_06_02_000001_create_nuers_tables.php', '2026_06_03_000002_add_api_token_to_users_table.php', '2026_06_02_000004_add_client_tin_to_profiles.php', '2026_06_02_000005_add_registration_address_to_merchants.php', '2026_06_02_000008_create_rdo_offices_table.php', '2026_10_15_000004_create_website_subscription_plans.php', '2026_10_15_000005_create_business_registration_records.php', '2026_10_15_000006_add_registration_address_details.php', '2026_10_15_000007_add_registration_industry_details.php', '2026_10_17_000007_single_transaction_pricing.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        DB::table('rdo_offices')->insert(['id' => (string) Str::uuid(), 'rdo_code' => '047', 'rdo_name' => 'East Makati', 'region' => 'NCR', 'status' => 'active']);
    }

    private function challenge(string $email = 'registration@example.test'): array
    {
        RateLimiter::clear('registration-email:'.hash('sha256', $email));
        $r = $this->postJson('/api/registration/email-code', ['email' => $email])->assertOk()->assertJsonMissingPath('development_code');
        $code = Mail::sent(RegistrationCodeMail::class)->last()->code;

        return [$r->json('challenge_id'), $code];
    }

    private function payload(): array
    {
        [$challenge,$code] = $this->challenge();
        $proof = $this->postJson('/api/registration/verify-code', ['challenge_id' => $challenge, 'code' => $code])->assertOk()->json('verification_token');

        return ['email' => 'registration@example.test', 'password' => 'ExamplePassword123', 'password_confirmation' => 'ExamplePassword123', 'tin' => '654-321-987-000', 'business_name' => 'Test Business', 'address' => 'Untrusted address override', 'street_address' => 'Unit 1 Example Street', 'zip_code' => '1229', 'region_code' => '1300000000', 'province_code' => '1300000000', 'city_code' => '1380300000', 'barangay_code' => '1380300025', 'business_type' => 'corporation', 'industry' => 'it', 'rdo_code' => '047', 'contact_name' => 'Test Contact', 'contact_position' => 'Owner', 'mobile' => '+639123456789', 'vat_registered' => true, 'subscription_plan_id' => 1, 'terms_accepted' => true, 'privacy_accepted' => true, 'notice_version' => '2026-10-17.2', 'challenge_id' => $challenge, 'verification_token' => $proof];
    }

    public function test_options_only_include_active_rdos(): void
    {
        DB::table('rdo_offices')->insert(['id' => (string) Str::uuid(), 'rdo_code' => '999', 'rdo_name' => 'Inactive', 'region' => 'NCR', 'status' => 'inactive']);
        $this->getJson('/api/registration/options')->assertOk();
        $offices = $this->getJson('/api/registration/options')->json('rdos');
        $this->assertNotContains('999', array_column($offices, 'rdo_code'));
        $this->assertSame('East Makati', collect($offices)->firstWhere('rdo_code', '047')['rdo_name']);
    }

    public function test_code_is_hashed_rate_limited_and_locks_after_five_wrong_attempts(): void
    {
        [$id,$code] = $this->challenge();
        $row = DB::table('registration_email_verifications')->first();
        $this->assertNotSame($code, $row->code_hash);
        $this->assertTrue(Hash::check($code, $row->code_hash));
        $this->postJson('/api/registration/email-code', ['email' => $row->email])->assertTooManyRequests();
        $wrong = $code === '111111' ? '222222' : '111111';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/registration/verify-code', ['challenge_id' => $id, 'code' => $wrong])->assertUnprocessable();
        }
        $this->assertEquals(5, DB::table('registration_email_verifications')->value('attempts'));
        $this->postJson('/api/registration/verify-code', ['challenge_id' => $id, 'code' => $code])->assertUnprocessable();
    }

    public function test_expired_code_cannot_be_verified(): void
    {
        [$id,$code] = $this->challenge();
        DB::table('registration_email_verifications')->where('id', $id)->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/registration/verify-code', ['challenge_id' => $id, 'code' => $code])->assertUnprocessable();
    }

    public function test_registration_creates_pending_merchant_and_consumes_proof_once(): void
    {
        $p = $this->payload();
        $this->postJson('/api/registration/business', $p)->assertCreated()->assertJsonPath('status', 'pending_review')->assertJsonPath('email_verified', true);
        $u = DB::table('users')->first();
        $this->assertTrue(Hash::check($p['password'], $u->password));
        $this->assertNull($u->api_token);
        $this->assertNotNull($u->email_verified_at);
        $this->assertDatabaseHas('profiles', ['id' => $u->id, 'role' => 'merchant', 'tin' => '654321987000']);
        $this->assertStringContainsString('San Lorenzo', DB::table('merchants')->value('address'));
        $this->assertStringNotContainsString('Untrusted', DB::table('merchants')->value('address'));
        $this->assertDatabaseHas('merchants', ['city' => 'City of Makati', 'zip_code' => '1229', 'merchant_account_id' => $u->id, 'tin' => '654321987000', 'status' => 'pending', 'rdo_code' => '047']);
        $r = DB::table('business_registrations')->first();
        $this->assertSame('Owner', $r->contact_position);
        $this->assertSame('2026-10-17.2', $r->terms_version);
        $this->assertEquals(300, json_decode($r->plan_snapshot)->fee_centavos);
        $this->assertFalse(json_decode($r->plan_snapshot)->subscription_required);
        $this->assertNotNull(DB::table('registration_email_verifications')->value('consumed_at'));
        $this->postJson('/api/registration/business', $p)->assertUnprocessable();
        $this->assertEquals(1, DB::table('users')->count());
    }

    public function test_validation_and_email_binding_prevent_partial_or_privileged_accounts(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\ScopedThrottleRequests::class);
        $p = $this->payload();
        foreach ([['role' => 'admin'], ['status' => 'active'], ['terms_accepted' => false], ['privacy_accepted' => false], ['tin' => '123'], ['mobile' => '+63123'], ['email' => 'different@example.test'], ['verification_token' => str_repeat('x', 64)], ['rdo_code' => '999'], ['city_code' => '1381100000'], ['barangay_code' => '1381100001'], ['zip_code' => '12'], ['region_code' => '0300000000']] as $override) {
            $this->postJson('/api/registration/business', array_replace($p, $override))->assertUnprocessable();
            $this->assertEquals(0, DB::table('users')->count());
        }
    }

    public function test_existing_tin_and_unpublished_plan_cannot_be_registered(): void
    {
        $p = $this->payload();
        DB::table('merchants')->insert(['id' => (string) Str::uuid(), 'business_name' => 'Existing', 'tin' => '654-321-987']);
        $this->postJson('/api/registration/business', $p)->assertUnprocessable()->assertJsonValidationErrors('tin');
        DB::table('website_subscription_plans')->where('id', 1)->update(['published' => false]);
        $this->postJson('/api/registration/business', $p)->assertUnprocessable()->assertJsonValidationErrors('subscription_plan_id');
        $this->assertEquals(0, DB::table('users')->count());
    }

    public function test_local_log_code_does_not_mark_email_verified(): void
    {
        $p = $this->payload();
        DB::table('registration_email_verifications')->where('id', $p['challenge_id'])->update(['development_delivery' => true]);
        $this->postJson('/api/registration/business', $p)->assertCreated()->assertJsonPath('email_verified', false)->assertJsonPath('development_delivery', true);
        $this->assertNull(DB::table('users')->value('email_verified_at'));
        $this->assertEquals(0, DB::table('business_registrations')->value('email_verified'));
    }

    public function test_location_hierarchy_and_rdo_resolution_are_scoped_and_do_not_guess(): void
    {
        $this->getJson('/api/registration/locations?level=regions')->assertOk()->assertJsonCount(18, 'data');
        $this->getJson('/api/registration/locations?level=provinces&parent=1300000000')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', '1300000000');
        $cities = $this->getJson('/api/registration/locations?level=cities&parent=1300000000')->assertOk()->json('data');
        $this->assertContains('1381100000', array_column($cities, 'code'));
        $this->assertNotContains('0102801000', array_column($cities, 'code'));
        $this->getJson('/api/registration/locations?level=barangays&parent=1381100000')->assertJsonCount(201, 'data');
        $this->getJson('/api/registration/locations?level=barangays&parent=1380600000')->assertJsonCount(897, 'data');
        $this->getJson('/api/registration/rdo?city_code=1381100000&barangay_code=1381100001')->assertOk()->assertJsonPath('automatic', true)->assertJsonPath('rdo.rdo_code', '051');
        $this->getJson('/api/registration/rdo?city_code=1380100000&barangay_code=1380100179')->assertOk()->assertJsonPath('automatic', true)->assertJsonPath('rdo.rdo_code', '027')->assertJsonPath('rdo.rdo_name', 'Caloocan City');
        $this->getJson('/api/registration/rdo?city_code=1380300000')->assertOk()->assertJsonPath('automatic', false)->assertJsonPath('rdo', null);
        $this->getJson('/api/registration/rdo?city_code=1380300000&barangay_code=1380300006')->assertOk()->assertJsonPath('rdo.rdo_code', '050');
        $this->getJson('/api/registration/rdo?city_code=1380300000&barangay_code=1381100001')->assertUnprocessable();
        $this->getJson('/api/registration/rdo?city_code=0000000000')->assertUnprocessable();
        $this->getJson('/api/registration/locations?level=arbitrary')->assertUnprocessable();
    }

    public function test_auto_rdo_is_enforced_and_structured_address_is_saved(): void
    {
        $p = array_replace($this->payload(), ['city_code' => '1381100000', 'barangay_code' => '1381100001', 'zip_code' => '1300']);
        $this->postJson('/api/registration/business', $p)->assertUnprocessable()->assertJsonValidationErrors('rdo_code');
        $p['rdo_code'] = '051';
        $this->postJson('/api/registration/business', $p)->assertCreated();
        $this->assertDatabaseHas('merchants', ['city' => 'Pasay City', 'rdo_code' => '051', 'zip_code' => '1300']);
        $details = json_decode(DB::table('business_registrations')->value('address_details'), true);
        $this->assertSame('1381100001', $details['barangay']['code']);
        $this->assertTrue($details['rdo_automatic']);
    }

    public function test_explicit_coverage_conflicts_do_not_silently_pick_first_office(): void
    {
        DB::table('rdo_offices')->where('rdo_code', '047')->update(['coverage_area' => json_encode(['psgc:barangay:1380300025'])]);
        $this->getJson('/api/registration/rdo?city_code=1380300000&barangay_code=1380300025')->assertJsonPath('rdo.rdo_code', '047');
        DB::table('rdo_offices')->insert(['id' => (string) Str::uuid(), 'rdo_code' => '048', 'rdo_name' => 'West Makati', 'region' => 'NCR', 'status' => 'active', 'coverage_area' => json_encode(['psgc:barangay:1380300025'])]);
        $this->getJson('/api/registration/rdo?city_code=1380300000&barangay_code=1380300025')->assertJsonPath('automatic', false);
    }

    public function test_industry_catalog_and_policy_versions_match_registration(): void
    {
        $o = $this->getJson('/api/registration/options')->assertOk()->json();
        $this->assertCount(1273, $o['industries']);
        $this->assertArrayHasKey('psic:62010', $o['industries']);
        $this->getJson('/api/legal/policies')->assertOk()->assertJsonPath('version', $o['notice_version'])->assertJsonPath('contact_email', 'nuersofficial@gmail.com');
        $p = $this->payload();
        $p['industry'] = 'other';
        $this->postJson('/api/registration/business', $p)->assertUnprocessable()->assertJsonValidationErrors('industry_other');
        $p['industry'] = 'psic:62010';
        $this->postJson('/api/registration/business', $p)->assertCreated();
        $industry = json_decode(DB::table('business_registrations')->value('industry_details'), true);
        $this->assertSame('62010', $industry['code']);
    }

    public function test_verifying_near_code_expiry_provides_a_fresh_submission_window(): void
    {
        [$id,$code] = $this->challenge();
        $this->travel(9)->minutes();
        $verified = $this->postJson('/api/registration/verify-code', ['challenge_id' => $id, 'code' => $code])->assertOk()->assertJsonPath('expires_in', 900);
        $this->assertTrue(Carbon::parse($verified->json('expires_at'))->isFuture());
        $this->travel(2)->minutes();
        app(RegistrationVerification::class)->requireValid(DB::table('registration_email_verifications')->where('id', $id)->first(), $verified->json('verification_token'), 'registration@example.test');
        $this->postJson('/api/registration/verify-code', ['challenge_id' => $id, 'code' => $code])->assertUnprocessable();
        $this->assertSame(hash('sha256', $verified->json('verification_token')), DB::table('registration_email_verifications')->where('id', $id)->value('proof_hash'));
    }

    public function test_expired_proof_fails_with_recoverable_error_and_new_code_can_complete_registration(): void
    {
        $p = $this->payload();
        DB::table('registration_email_verifications')->where('id', $p['challenge_id'])->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/registration/business', $p)->assertUnprocessable()->assertJsonPath('code', 'REGISTRATION_VERIFICATION_REQUIRED');
        $this->assertEquals(0, DB::table('users')->count());
        RateLimiter::clear('registration-email:'.hash('sha256', $p['email']));
        $new = $this->postJson('/api/registration/email-code', ['email' => $p['email'], 'previous_challenge_id' => $p['challenge_id']])->assertOk()->assertJsonPath('resend_after', 60);
        $this->assertNull(DB::table('registration_email_verifications')->where('id', $p['challenge_id'])->value('proof_hash'));
        $code = Mail::sent(RegistrationCodeMail::class)->last()->code;
        $token = $this->postJson('/api/registration/verify-code', ['challenge_id' => $new->json('challenge_id'), 'code' => $code])->assertOk()->json('verification_token');
        $p['challenge_id'] = $new->json('challenge_id');
        $p['verification_token'] = $token;
        $this->postJson('/api/registration/business', $p)->assertCreated();
    }

    public function test_replacement_revokes_only_the_matching_email_challenge(): void
    {
        [$old,$code] = $this->challenge();
        [$other] = $this->challenge('other@example.test');
        $this->travel(61)->seconds();
        $new = $this->postJson('/api/registration/email-code', ['email' => 'registration@example.test', 'previous_challenge_id' => $old])->assertOk();
        $this->assertNotSame($old, $new->json('challenge_id'));
        $this->postJson('/api/registration/verify-code', ['challenge_id' => $old, 'code' => $code])->assertUnprocessable();
        $this->assertTrue(Carbon::parse(DB::table('registration_email_verifications')->where('id', $other)->value('expires_at'))->isFuture());
        $this->travel(61)->seconds();
        $this->postJson('/api/registration/email-code', ['email' => 'registration@example.test', 'previous_challenge_id' => $other])->assertOk();
        $this->assertTrue(Carbon::parse(DB::table('registration_email_verifications')->where('id', $other)->value('expires_at'))->isFuture());
    }

    public function test_verification_at_exact_expiry_is_rejected(): void
    {
        [$id,$code] = $this->challenge();
        $this->travelTo(Carbon::parse(DB::table('registration_email_verifications')->where('id',$id)->value('expires_at')));
        $this->postJson('/api/registration/verify-code',['challenge_id' => $id, 'code' => $code])->assertUnprocessable();
    }
}
