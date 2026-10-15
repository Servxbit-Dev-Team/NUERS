<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        (require database_path('migrations/2026_10_15_000001_add_google_id_to_users_table.php'))->up();
        Schema::create('profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('email');
            $table->string('role');
            $table->timestamps();
        });
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret', 'services.google.frontend_url' => 'http://localhost:8001']);
    }

    private function account(string $email = 'member@gmail.com', string $role = 'merchant'): User
    {
        $user = User::factory()->create(['email' => $email, 'api_token' => 'existing-session']);
        Profile::create(['id' => $user->id, 'email' => $email, 'role' => $role]);

        return $user;
    }

    private function provider(array $claims = []): MockHandler
    {
        $handler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'test-provider-token', 'token_type' => 'Bearer'])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(array_replace(['sub' => 'google-subject-123', 'email' => 'member@gmail.com', 'email_verified' => true, 'name' => 'Member'], $claims))),
        ]);
        Socialite::shouldReceive('driver')->with('google')->andReturnUsing(function () use ($handler) {
            return (new GoogleProvider(app('request'), 'test-client', 'test-secret', 'http://localhost:8001/auth/google/callback'))
                ->setHttpClient(new Client(['handler' => HandlerStack::create($handler)]));
        });

        return $handler;
    }

    private function oauthCallback(): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['state' => 'valid-state'])->get('/auth/google/callback?state=valid-state&code=test-code');
    }

    public function test_missing_credentials_returns_a_friendly_login_status(): void
    {
        config(['services.google.client_id' => null]);
        $this->get('/auth/google/redirect')->assertRedirectContains('http://localhost:8001/login?google_error=not_configured');
    }

    public function test_login_redirects_bypass_cached_spa_documents_without_exposing_credentials(): void
    {
        config(['services.google.client_id' => null]);
        $first = $this->get('/auth/google/redirect');
        $second = $this->get('/auth/google/redirect');
        $this->assertNotSame($first->headers->get('Location'), $second->headers->get('Location'));
        foreach ([$first, $second] as $response) {
            $response->assertRedirectContains('http://localhost:8001/login?google_error=not_configured');
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            $this->assertSame(['google_error', 'v'], array_keys($query));
            $this->assertNotEmpty($query['v']);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
    }

    public function test_authorization_redirect_contains_state_and_uses_google(): void
    {
        $response = $this->get('/auth/google/redirect');
        $response->assertRedirect();
        $url = $response->headers->get('Location');
        $this->assertSame('accounts.google.com', parse_url($url, PHP_URL_HOST));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['state']);
        $this->assertSame(session('state'), $query['state']);
        $this->assertSame('select_account', $query['prompt']);
    }

    public function test_invalid_state_is_rejected_before_contacting_google(): void
    {
        $handler = $this->provider();
        $this->withSession(['state' => 'valid-state'])->get('/auth/google/callback?state=attacker-state&code=test-code')
            ->assertRedirectContains('http://localhost:8001/login?google_error=invalid_state');
        $this->assertSame(2, $handler->count());
        $this->assertDatabaseCount('users', 0);
    }

    public function test_verified_existing_account_preserves_role_and_exchanges_session_once(): void
    {
        $user = $this->account(role: 'bir');
        $this->provider();
        $this->oauthCallback()->assertRedirectContains('http://localhost:8001/login?google=success');
        $this->assertSame('google-subject-123', $user->fresh()->google_id);
        $this->assertSame('existing-session', $user->fresh()->api_token);
        $this->assertSame('bir', Profile::find($user->id)->role);
        $response = $this->getJson('/auth/google/session')->assertOk()->assertJsonPath('session.user.id', (string) $user->id)->assertJsonPath('role', 'bir');
        $response->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNotSame('existing-session', $response->json('session.access_token'));
        $this->getJson('/auth/google/session')->assertUnauthorized();
    }

    public function test_unverified_email_cannot_link_an_account(): void
    {
        $user = $this->account();
        $this->provider(['email_verified' => false]);
        $this->oauthCallback()->assertRedirectContains('http://localhost:8001/login?google_error=unverified');
        $this->assertNull($user->fresh()->google_id);
        $this->assertSame('existing-session', $user->fresh()->api_token);
    }

    public function test_unknown_google_account_does_not_create_a_nuers_account(): void
    {
        $this->provider();
        $this->oauthCallback()->assertRedirectContains('http://localhost:8001/login?google_error=account_not_found');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('profiles', 0);
    }

    public function test_google_cannot_automatically_link_an_unmanaged_third_party_email(): void
    {
        $user = $this->account('member@example.com');
        $this->provider(['email' => 'member@example.com']);
        $this->oauthCallback()->assertRedirectContains('http://localhost:8001/login?google_error=email_link_unavailable');
        $this->assertNull($user->fresh()->google_id);
    }

    public function test_verified_workspace_account_can_link_to_its_existing_account(): void
    {
        $user = $this->account('member@example.com');
        $this->provider(['email' => 'member@example.com', 'hd' => 'example.com']);
        $this->oauthCallback()->assertRedirectContains('http://localhost:8001/login?google=success');
        $this->assertSame('google-subject-123', $user->fresh()->google_id);
    }

    public function test_linked_subject_is_used_even_when_the_google_email_changes(): void
    {
        $user = $this->account();
        $user->forceFill(['google_id' => 'google-subject-123'])->save();
        $this->provider(['email' => 'renamed@gmail.com']);
        $this->oauthCallback()->assertRedirectContains('http://localhost:8001/login?google=success');
        $this->getJson('/auth/google/session')->assertOk()->assertJsonPath('user.id', (string) $user->id);
        $this->assertSame('member@gmail.com', $user->fresh()->email);
    }

    public function test_different_subject_cannot_replace_an_existing_link(): void
    {
        $user = $this->account();
        $user->forceFill(['google_id' => 'original-subject'])->save();
        $this->provider();
        $this->oauthCallback()->assertRedirectContains('http://localhost:8001/login?google_error=identity_conflict');
        $this->assertSame('original-subject', $user->fresh()->google_id);
    }

    public function test_unassigned_role_cannot_sign_in_or_link(): void
    {
        $user = $this->account(role: 'unassigned');
        $this->provider();
        $this->oauthCallback()->assertRedirectContains('http://localhost:8001/login?google_error=account_unavailable');
        $this->assertNull($user->fresh()->google_id);
    }

    public function test_expired_handoff_cannot_issue_a_token(): void
    {
        $user = $this->account();
        $this->withSession(['google_login' => ['user_id' => $user->id, 'expires_at' => now()->subMinute()->timestamp]])
            ->getJson('/auth/google/session')->assertUnauthorized();
        $this->assertSame('existing-session', $user->fresh()->api_token);
    }
}
