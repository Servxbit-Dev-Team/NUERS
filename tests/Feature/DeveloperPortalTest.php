<?php

namespace Tests\Feature;

use App\Services\IntegrationContract;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeveloperPortalTest extends TestCase
{
    private function requestPayload(array $changes = []): array
    {
        return ['is_test' => true, 'action' => 'preview', 'payload' => array_replace(['external_reference' => 'TEST-POS-001', 'amount' => 1120, 'tax_type' => 'vatable'], $changes)];
    }

    public function test_public_preview_computes_tax_without_financial_tables_or_bir_calls(): void
    {
        $this->postJson('/api/developer/sandbox', $this->requestPayload())->assertOk()
            ->assertJsonPath('persisted', false)->assertJsonPath('bir_contacted', false)
            ->assertJsonPath('bir_status', 'not_submitted')->assertJsonPath('calculation.vat_amount', 120)
            ->assertJsonPath('calculation.vatable_sales', 1000)->assertJsonPath('simulated_http_status', 201);
        $this->assertFalse(Schema::hasTable('transactions'));
        $this->assertFalse(Schema::hasTable('receipts'));
    }

    public function test_preview_tax_modes_and_gross_precedence_match_shared_contract(): void
    {
        $this->postJson('/api/developer/sandbox', $this->requestPayload(['amount' => 1000, 'vat_inclusive' => false]))->assertOk()->assertJsonPath('calculation.gross_amount', 1120);
        $this->postJson('/api/developer/sandbox', $this->requestPayload(['tax_type' => 'zero_rated']))->assertOk()->assertJsonPath('calculation.zero_rated_sales', 1120)->assertJsonPath('calculation.vat_amount', 0);
        $this->postJson('/api/developer/sandbox', $this->requestPayload(['amount' => 9999, 'gross_amount' => 1120]))->assertOk()->assertJsonPath('calculation.vat_amount', 120);
        $this->assertEquals(120, app(IntegrationContract::class)->computeTax(1120, 'vatable', 12, true)['vat_amount']);
    }

    public function test_invalid_contract_reports_field_errors(): void
    {
        $this->postJson('/api/developer/sandbox', $this->requestPayload(['tax_type' => 'invalid', 'amount' => -1]))->assertStatus(422)->assertJsonValidationErrors(['tax_type', 'amount'])->assertJsonPath('persisted', false);
        $p = $this->requestPayload();
        unset($p['payload']['amount']);
        $this->postJson('/api/developer/sandbox', $p)->assertStatus(422)->assertJsonValidationErrors(['amount', 'gross_amount']);
    }

    public function test_sandbox_rejects_secrets_real_tins_non_test_identity_and_size(): void
    {
        $this->postJson('/api/developer/sandbox', $this->requestPayload(['external_reference' => 'REAL-1']))->assertStatus(422);
        $this->postJson('/api/developer/sandbox', $this->requestPayload(['customer_tin' => '123456789000']))->assertStatus(422);
        $this->postJson('/api/developer/sandbox', $this->requestPayload(['items' => [['api_key' => 'synthetic-secret']]]))->assertStatus(422);
        $this->postJson('/api/developer/sandbox', $this->requestPayload(), ['Authorization' => 'Bearer example-invalid'])->assertStatus(422);
        $this->postJson('/api/developer/sandbox', $this->requestPayload(['source_system' => str_repeat('x', 66000)]))->assertStatus(413);
    }

    public function test_explicit_test_consent_and_preview_ceiling_are_enforced(): void
    {
        $this->postJson('/api/developer/sandbox', array_replace($this->requestPayload(), ['is_test' => false]))->assertStatus(422);
        $this->postJson('/api/developer/sandbox', $this->requestPayload(['amount' => 1000000001]))->assertStatus(422);
    }

    public function test_scenarios_are_marked_as_simulations_with_actual_http_200(): void
    {
        foreach (['duplicate' => 200, 'conflict' => 409, 'unauthorized' => 401, 'forbidden' => 403, 'rate_limit' => 429, 'timeout' => 504, 'production_gate' => 409] as $scenario => $code) {
            $this->postJson('/api/developer/sandbox', ['is_test' => true, 'action' => 'scenario', 'scenario' => $scenario])->assertOk()->assertJsonPath('simulated_http_status', $code)->assertJsonPath('is_test', true)->assertJsonPath('persisted', false)->assertJsonPath('bir_contacted', false);
        }
    }

    public function test_public_sandbox_is_rate_limited(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/developer/sandbox', $this->requestPayload())->assertOk();
        }
        $this->postJson('/api/developer/sandbox', $this->requestPayload())->assertStatus(429);
    }

    public function test_downloaded_catalogue_and_openapi_only_reference_registered_routes(): void
    {
        $catalog = json_decode($this->get('/api/developer/catalog')->assertOk()->baseResponse->getFile()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $spec = json_decode($this->get('/api/developer/openapi')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="nuers-openapi.json"')->baseResponse->getFile()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('3.1.0', $spec['openapi']);
        foreach (['/api/docs', '/api/sandbox', '/developers/integration'] as $page) {
            $this->get($page)->assertOk()->assertSee('id="root"', false);
        }
        $this->assertCount(15, $catalog['endpoints']);
        foreach ($catalog['endpoints'] as $endpoint) {
            $route = collect(Route::getRoutes())->first(fn ($r) => $r->uri() === ltrim($endpoint['path'], '/') && in_array($endpoint['method'], $r->methods()));
            $this->assertNotNull($route, $endpoint['method'].' '.$endpoint['path']);
            $this->assertArrayHasKey(strtolower($endpoint['method']), $spec['paths'][$endpoint['path']]);
        }
    }
}
