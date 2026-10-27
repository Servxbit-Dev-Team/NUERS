<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Guards for the pre-deployment hygiene fixes (docs/refactor-plan.md, step 7). */
class DeployHygieneTest extends TestCase
{
    public function test_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue($this->app->isProduction());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must not run when APP_ENV=production');
        // Throws before any database access, so no demo account or password can be written.
        (new DatabaseSeeder)->run();
    }

    public function test_database_probe_script_is_not_in_the_web_root_or_deployment_folder(): void
    {
        $this->assertFileDoesNotExist(public_path('nuers-db-check.php'));
        $this->assertFileDoesNotExist(base_path('deployment/nuers-db-check.php'));
        $this->assertFileDoesNotExist(base_path('nuers-db-check.php'));
    }

    public function test_env_example_documents_the_settings_without_carrying_secrets_or_unsafe_defaults(): void
    {
        $example = file_get_contents(base_path('.env.example'));
        $values = [];
        foreach (preg_split('/\R/', $example) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $m)) {
                $values[$m[1]] = trim($m[2], " \"'");
            }
        }

        $this->assertSame('production', $values['APP_ENV'] ?? null);
        $this->assertSame('false', $values['APP_DEBUG'] ?? null);
        foreach (['APP_KEY', 'DB_PASSWORD', 'NUERS_AUDIT_CHECKPOINT_KEY', 'GOOGLE_CLIENT_SECRET', 'NUERS_RELEASE_EVIDENCE_REFERENCE'] as $secret) {
            $this->assertArrayHasKey($secret, $values, "$secret should be documented.");
            $this->assertSame('', $values[$secret], "$secret must be blank in the example file.");
        }
        $this->assertSame('false', $values['NUERS_NOTIFICATION_DISPATCH_ENABLED'] ?? null, 'Customer notifications stay off by default.');
    }

    public function test_application_routes_are_controller_based(): void
    {
        $closures = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => $r->getAction('uses') instanceof \Closure)
            ->reject(fn ($r) => $r->uri() === 'up' || str_starts_with($r->uri(), 'storage/')) // the framework's own health and local-disk routes
            ->map(fn ($r) => $r->methods()[0].' /'.$r->uri())
            ->values()->all();

        $this->assertSame([], $closures, 'Application routes should point at controllers.');
    }

    public function test_unknown_receipt_verification_is_a_clean_404(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

        $this->getJson('/api/verify-receipt/NOT-A-REAL-RECEIPT')->assertNotFound();
        $this->assertDatabaseCount('receipt_verifications', 0);
    }
}
