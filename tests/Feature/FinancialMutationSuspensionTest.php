<?php

namespace Tests\Feature;

use App\Services\MerchantWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PositiveFlowCase;

class FinancialMutationSuspensionTest extends PositiveFlowCase
{
    public static function cases(): array
    {
        $cases = [];
        foreach (['merchant', 'admin', 'super_admin'] as $role) {
            foreach (['suspended', 'inactive'] as $status) {
                foreach (['invoice', 'topup', 'settings'] as $route) {
                    $cases["$role-$status-$route"] = [$role, $status, $route];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_committed_suspension_is_rechecked_before_financial_mutation(string $role, string $status, string $route): void
    {
        [$user, $headers] = $this->actor($role);
        $merchant = $this->business($user);
        DB::transaction(fn () => app(MerchantWallet::class)->credit($merchant, 1000, (string) Str::uuid(), 'Synthetic opening', 'test'));
        $before = (array) DB::table('merchant_wallets')->where('merchant_id', $merchant)->first();
        $paused = false;
        // QueryExecuted runs after the preflight SELECT has fetched its stale active row.
        // The process-race suite independently exercises this ordering on MariaDB.
        DB::listen(function ($query) use (&$paused, $merchant, $status) {
            if (! $paused && DB::transactionLevel() === 0 && preg_match('/^select \* from [`"]merchants[`"]/', $query->sql)) {
                $paused = true;
                DB::table('merchants')->where('id', $merchant)->update(['status' => $status]);
            }
        });
        $response = match ($route) {
            'invoice' => $this->postJson('/api/business-invoices', [
                'request_id' => (string) Str::uuid(), 'invoice_number' => 'SYN-DRAFT',
                'document_type' => 'sales_invoice', 'buyer_name' => 'Synthetic Buyer',
                'buyer_customer_type' => 'retail', 'buyer_email' => 'synthetic@example.test',
                'issue_date' => today()->toDateString(), 'status' => 'draft',
                'line_items' => [['line_number' => 1, 'description' => 'Synthetic item', 'quantity' => 1, 'unit_price' => 560, 'line_total' => 560, 'tax_type' => 'vatable']],
            ], $headers),
            'topup' => $this->postJson('/api/merchant/wallet/topups', [
                'request_id' => (string) Str::uuid(), 'amount_centavos' => 10000,
                'payment_method' => 'bank_transfer', 'payment_reference' => 'SYNTHETIC-FUNDING',
                'payment_date' => today()->toDateString(),
            ], $headers),
            'settings' => $this->putJson('/api/merchant/wallet/settings', [
                'low_balance_threshold' => 100, 'alerts_enabled' => false,
                'intake_mode' => 'batch', 'revision' => $before['revision'],
            ], $headers),
        };
        $this->assertTrue($paused, 'Preflight race hook must actually execute');
        $response->assertForbidden();
        $this->assertDatabaseCount('business_invoices', 0);
        $this->assertDatabaseCount('merchant_wallet_topups', 0);
        $this->assertSame($before, (array) DB::table('merchant_wallets')->where('merchant_id', $merchant)->first());
        $this->assertSame(['valid' => true, 'entries' => 1, 'balance_centavos' => 1000], app(MerchantWallet::class)->audit($merchant));
    }
}
