<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EisDocumentReadiness;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSecurityDatabase;
use Tests\TestCase;

class StatefulResourceBoundaryTest extends TestCase
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

    private function business(User $u): string
    {
        $id = (string) Str::uuid();
        DB::table('merchants')->insert(['id' => $id, 'merchant_account_id' => $u->id, 'business_name' => 'Synthetic business '.$u->id, 'tin' => '123456789000', 'status' => 'active']);

        return $id;
    }

    private function customerPayload(): array
    {
        return ['request_id' => (string) Str::uuid(), 'name' => 'Synthetic private customer', 'type' => 'retail', 'status' => 'active',
            'group_name' => 'Synthetic', 'email' => 'synthetic-buyer@example.test', 'reason' => 'Synthetic authorized security test'];
    }

    private function key(string $merchant): string
    {
        $id = (string) Str::uuid();
        DB::table('merchant_api_keys')->insert(['id' => $id, 'merchant_id' => $merchant, 'name' => 'Synthetic retained key',
            'environment' => 'sandbox', 'branch_code' => '00000', 'branch_type' => 'main', 'key_hash' => hash('sha256', Str::random(64)),
            'key_prefix' => 'synthetic', 'key_last_four' => 'test', 'status' => 'active', 'scopes' => '["transactions:read","transactions:write"]',
            'ip_whitelist' => '[]', 'rate_limit_per_day' => 1000, 'expires_at' => now()->addDay()]);

        return $id;
    }

    public function test_existing_customer_read_write_and_document_link_targets_are_scoped_for_every_role(): void
    {
        [$foreign, $fh] = $this->actor('merchant');
        $fm = $this->business($foreign);
        $foreignId = $this->postJson('/api/merchant/customers', $this->customerPayload(), $fh)->assertCreated()->json('data.id');
        $foreignBefore = (array) DB::table('merchant_customers')->find($foreignId);
        $receipt = (string) Str::uuid();
        DB::table('transaction_receipts')->insert(['id' => $receipt, 'merchant_id' => $fm, 'receipt_number' => 'FOREIGN-STATEFUL-1', 'merchant_name' => 'Synthetic foreign supplier', 'merchant_tin' => '123456789000', 'status' => 'issued', 'total_due' => 100, 'gross_amount' => 100]);
        foreach (['super_admin', 'admin', 'bir', 'rdo', 'merchant', 'client', 'consumer'] as $role) {
            [$u, $h] = $this->actor($role);
            $own = $this->business($u);
            $allowed = in_array($role, ['super_admin', 'admin', 'merchant'], true);
            $payload = $this->customerPayload();
            $this->getJson('/api/merchant/customers/'.$foreignId.'?merchant_id='.$fm, $h)->assertStatus($allowed ? 404 : 403);
            $this->patchJson('/api/merchant/customers/'.$foreignId, $payload + ['revision' => 1, 'merchant_id' => $fm], $h)->assertStatus($allowed ? 404 : 403);
            $r = $this->postJson('/api/merchant/customers', $payload + ['merchant_id' => $fm, 'user_id' => $foreign->id], $h)->assertStatus($allowed ? 201 : 403);
            if ($allowed) {
                $id = $r->json('data.id');
                $this->assertDatabaseHas('merchant_customers', ['id' => $id, 'merchant_id' => $own]);
                $this->getJson('/api/merchant/customers/'.$id, $h)->assertOk()->assertJsonPath('data.id', $id);
                $this->postJson('/api/merchant/customers', $payload, $h)->assertOk()->assertJsonPath('data.id', $id);
                $this->postJson('/api/merchant/customers', array_replace($payload, ['name' => 'Changed replay']), $h)->assertConflict();
                $update = array_replace($payload, ['name' => 'Authorized change', 'revision' => 1]);
                $this->patchJson('/api/merchant/customers/'.$id, $update, $h)->assertOk()->assertJsonPath('data.revision', 2);
                $this->patchJson('/api/merchant/customers/'.$id, $update, $h)->assertConflict();
                $this->postJson('/api/merchant/customers/'.$id.'/documents', ['document_source' => 'receipt', 'document_id' => $receipt, 'reason' => 'Synthetic cross-tenant attempt'], $h)->assertNotFound();
            }
            $this->assertSame($foreignBefore, (array) DB::table('merchant_customers')->find($foreignId));
        }
        $this->assertDatabaseCount('merchant_customer_documents', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_existing_api_credentials_and_document_copies_cannot_be_retargeted_by_any_role(): void
    {
        [$foreign] = $this->actor('merchant');
        $fm = $this->business($foreign);
        $fk = $this->key($fm);
        $receipt = (string) Str::uuid();
        DB::table('transaction_receipts')->insert(['id' => $receipt, 'merchant_id' => $fm, 'receipt_number' => 'PRIVATE-STATEFUL-2', 'merchant_name' => 'Synthetic foreign supplier', 'merchant_tin' => '123456789000', 'status' => 'issued', 'total_due' => 100, 'gross_amount' => 100]);
        $before = (array) DB::table('merchant_api_keys')->find($fk);
        foreach (['super_admin', 'admin', 'bir', 'rdo', 'merchant', 'client', 'consumer'] as $role) {
            [$u, $h] = $this->actor($role);
            $own = $this->business($u);
            $ok = $this->key($own);
            $allowed = in_array($role, ['super_admin', 'admin', 'merchant'], true);
            $this->getJson('/api/merchant/api-keys?merchant_id='.$fm, $h)->assertStatus($allowed ? 200 : 403)->assertJsonMissing(['id' => $fk]);
            $this->patchJson('/api/merchant/api-keys/'.$fk, ['name' => 'Retargeted', 'merchant_id' => $fm], $h)->assertStatus($allowed ? 404 : 403);
            $this->deleteJson('/api/merchant/api-keys/'.$fk, ['merchant_id' => $fm], $h)->assertStatus($allowed ? 404 : 403);
            $this->patchJson('/api/merchant/api-keys/'.$ok, ['name' => 'Own authorized edit'], $h)->assertStatus($allowed ? 200 : 403);
            $this->deleteJson('/api/merchant/api-keys/'.$ok, [], $h)->assertStatus($allowed ? 200 : 403);
            $this->assertDatabaseHas('merchant_api_keys', ['id' => $ok, 'status' => $allowed ? 'revoked' : 'active']);
            foreach (['', '/pdf'] as $suffix) {
                $this->getJson('/api/document-copies/receipt/'.$receipt.$suffix.'?merchant_id='.$fm, $h)->assertStatus($allowed ? 404 : 403);
            }
            $this->postJson('/api/document-copies/receipt/'.$receipt.'/email', ['email' => 'synthetic-recipient@example.test', 'request_id' => (string) Str::uuid()], $h)->assertStatus($allowed ? 404 : 403);
            $this->assertSame($before, (array) DB::table('merchant_api_keys')->find($fk));
        }
        $this->assertDatabaseCount('document_email_deliveries', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_expense_review_requires_an_independent_privileged_actor_and_intact_source(): void
    {
        [$buyer] = $this->actor('merchant');
        $bm = $this->business($buyer);
        DB::table('business_invoices')->insert(['id' => 'review-source', 'merchant_id' => 'synthetic-seller', 'invoice_number' => 'SYN-REVIEW', 'document_type' => 'vat_invoice', 'merchant_name' => 'Synthetic seller', 'merchant_tin' => '987654321000', 'merchant_address' => 'Synthetic address', 'seller_branch_code' => '00000', 'buyer_name' => 'Synthetic buyer', 'buyer_tin' => '123456789000', 'status' => 'issued', 'issue_date' => now()->toDateString(), 'total_amount' => 112, 'subtotal_amount' => 112, 'taxable_amount' => 100, 'vat_amount' => 12, 'vat_registered' => true, 'prices_include_vat' => true]);
        DB::table('business_invoice_items')->insert(['id' => 'review-line', 'invoice_id' => 'review-source', 'merchant_id' => 'synthetic-seller', 'line_number' => 1, 'description' => 'Synthetic source item', 'quantity' => 1, 'unit_price' => 112, 'line_total' => 112, 'taxable_amount' => 100, 'vat_amount' => 12, 'vat_rate' => 12, 'tax_type' => 'vatable']);
        $this->assertTrue(app(EisDocumentReadiness::class)->inspect('invoice', 'review-source')['financial_validation_passed']);
        DB::table('merchant_expenses')->insert(['id' => 'review-expense', 'merchant_id' => $bm, 'source_invoice_id' => 'review-source', 'source_invoice_reference' => 'SYN-REVIEW', 'supplier_name' => 'Synthetic seller', 'buyer_tin' => '123456789000', 'gross_amount' => 112, 'input_vat_amount' => 12, 'claim_status' => 'pending_review']);
        $path = '/api/merchant/expenses/review-expense/review';
        $payload = ['status' => 'approved', 'evidence_reference' => 'SYNTHETIC-REVIEW-EVIDENCE', 'reason' => 'Synthetic independent review'];
        foreach (['super_admin', 'admin', 'bir', 'rdo', 'merchant', 'client', 'consumer'] as $role) {
            [$u, $h] = $this->actor($role);
            DB::table('merchant_expenses')->where('id', 'review-expense')->update(['claim_status' => 'pending_review', 'metadata' => '{}']);
            $this->postJson($path, $payload + ['reviewed_by' => $buyer->id], $h)->assertStatus(in_array($role, ['super_admin', 'admin']) ? 200 : 403);
            $row = DB::table('merchant_expenses')->find('review-expense');
            if (in_array($role, ['super_admin', 'admin'])) {
                $this->assertSame('approved', $row->claim_status);
                $this->assertSame((string) $u->id, json_decode($row->metadata, true)['claim_review']['reviewed_by']);
                DB::table('merchants')->where('id', $bm)->update(['merchant_account_id' => $u->id]);
                $before = (array) $row;
                $this->postJson($path, $payload, $h)->assertForbidden();
                DB::table('merchants')->where('id', $bm)->update(['merchant_account_id' => $buyer->id]);
                foreach (['buyer_tin' => '444444444000', 'vat_amount' => 99, 'status' => 'cancelled'] as $field => $value) {
                    $old = DB::table('business_invoices')->where('id', 'review-source')->value($field);
                    DB::table('business_invoices')->where('id', 'review-source')->update([$field => $value]);
                    $this->postJson($path, $payload, $h)->assertConflict();
                    $this->assertSame($before, (array) DB::table('merchant_expenses')->find('review-expense'));
                    DB::table('business_invoices')->where('id', 'review-source')->update([$field => $old]);
                }
            } else {
                $this->assertSame('pending_review', $row->claim_status);
                $this->assertSame('{}', $row->metadata);
            }
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_generic_put_alias_cannot_bypass_role_identity_or_financial_workflows(): void
    {
        [$target] = $this->actor('client');
        foreach (['super_admin', 'admin', 'bir', 'rdo', 'merchant', 'client', 'consumer'] as $role) {
            [$u, $h] = $this->actor($role);
            $path = '/api/tables/profiles?'.http_build_query(['eq' => json_encode(['id' => $u->id])]);
            foreach (['tin' => '444444444000', 'mfa_enabled' => false, 'role' => 'bir', 'email' => 'attacker@example.test'] as $field => $value) {
                $this->putJson($path, ['values' => [$field => $value]], $h)->assertForbidden();
            }
            foreach (['merchants', 'transaction_receipts', 'merchant_transactions'] as $table) {
                $this->putJson('/api/tables/'.$table, ['values' => ['status' => 'issued']], $h)->assertStatus($role === 'rdo' ? 403 : 409);
            }
            if (in_array($role, ['super_admin', 'admin', 'bir', 'client', 'consumer'])) {
                $targetId = $role === 'admin' ? $target->id : $u->id;
                $this->putJson('/api/tables/profiles?'.http_build_query(['eq' => json_encode(['id' => $targetId])]), ['values' => ['full_name' => 'Synthetic authorized edit']], $h)->assertOk()->assertJsonPath('count', 1);
                $this->assertDatabaseHas('profiles', ['id' => $targetId, 'full_name' => 'Synthetic authorized edit']);
            }
            $this->assertDatabaseHas('profiles', ['id' => $u->id, 'role' => $role, 'email' => $u->email]);
        }
        Mail::assertNothingSent();
    }
}
