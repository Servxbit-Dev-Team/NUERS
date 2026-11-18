<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSecurityDatabase;
use Tests\TestCase;

class PublicLocationBoundaryTest extends TestCase
{
    use IsolatedSecurityDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->securityDatabase();
        $this->securityMigrate();
        config(['cache.default' => 'array', 'app.debug' => false, 'security.enforce_privileged_mfa' => true, 'notifications.dispatch_enabled' => false]);
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
    }

    private function actor(string $role): array
    {
        $token = Str::random(64);
        $device = (string) Str::uuid();
        $u = User::factory()->create(['api_token' => $token]);
        $u->forceFill(['api_device_hash' => hash('sha256', $device), 'mfa_secret' => Crypt::encryptString('SYNTHETICKEY'),
            'api_mfa_verified_at' => now(), 'api_last_activity_at' => now()])->save();
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'full_name' => 'Original synthetic name', 'role' => $role]);

        return [$u, ['Authorization' => 'Bearer '.$token, 'X-NUERS-Device' => $device]];
    }

    public function test_location_query_shapes_are_rejected_before_outbound_requests(): void
    {
        foreach ([['cities', 'country', ['PH']], ['barangays', 'cityCode', ['137602000']],
            ['barangays', 'city', ['Makati']], ['rdo', 'city', ['Makati']], ['rdo', 'region', ['NCR']],
            ['rdo', 'province', ['NCR']], ['rdo', 'barangay', ['Poblacion']],
            ['barangays', 'cityCode', '../cities?x='], ['barangays', 'cityCode', 'https://example.test'],
            ['barangays', 'cityCode', str_repeat('1', 1000)], ['rdo', 'city', str_repeat('x', 256)]] as [$route, $field, $value]) {
            $this->getJson('/api/locations/'.$route.'?'.http_build_query([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        Http::assertNothingSent();
    }

    public function test_public_location_and_developer_resources_support_valid_requests_with_every_role(): void
    {
        Http::fake(['https://psgc.gitlab.io/api/cities-municipalities/' => Http::response([['code' => '137602000', 'name' => 'Makati City']]),
            'https://psgc.gitlab.io/api/cities-municipalities/137602000/barangays/' => Http::response([['code' => '137602001', 'name' => 'Poblacion']])]);
        foreach ([null, 'super_admin', 'admin', 'bir', 'rdo', 'merchant', 'client', 'consumer'] as $role) {
            $h = $role ? $this->actor($role)[1] : [];
            $this->getJson('/api/locations/countries', $h)->assertOk()->assertJsonPath('data.0.code', 'PH');
            $this->getJson('/api/locations/cities?country=PH', $h)->assertOk()->assertJsonFragment(['code' => '137602000']);
            $this->getJson('/api/locations/cities?country=US', $h)->assertOk()->assertExactJson(['data' => []]);
            $this->getJson('/api/locations/barangays?cityCode=137602000', $h)->assertOk()->assertJsonPath('data.0.name', 'Poblacion');
            $this->getJson('/api/locations/barangays?cityCode=LOCAL-MAKATI-CITY&city=Makati', $h)->assertOk()->assertJsonFragment(['name' => 'Poblacion']);
            $this->getJson('/api/locations/rdo?city=Makati&region=NCR', $h)->assertOk()->assertJsonStructure(['data']);
            $this->getJson('/api/developer/catalog', $h)->assertOk();
            $this->getJson('/api/developer/openapi', $h)->assertOk();
        }
        $this->get('/api/sandbox')->assertOk();
        Mail::assertNothingSent();
    }
}
