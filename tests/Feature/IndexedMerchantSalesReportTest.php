<?php

namespace Tests\Feature;

use App\Services\LedgerProjection;
use Illuminate\Support\Facades\DB;

/** Run the existing reconciliation/security contract against the indexed path too. */
class IndexedMerchantSalesReportTest extends MerchantSalesReportTest
{
    protected function setUp(): void
    {
        parent::setUp();
        app(LedgerProjection::class)->activate();
    }

    public function test_oversized_reports_require_narrower_filters_instead_of_silent_truncation(): void
    {
        $merchant = DB::table('merchants')->where('tin', '123456789')->first();
        $owner = \App\Models\User::findOrFail($merchant->merchant_account_id);
        $owner->api_token = 'synthetic-report-token'; $owner->save();
        $token = $owner->api_token;
        for ($i = 0; $i < 2001; $i++) {
            DB::table('business_invoices')->insert(['id' => 'scale-'.$i, 'merchant_id' => $merchant->id, 'merchant_name' => 'Synthetic scale merchant', 'buyer_name' => 'Buyer', 'invoice_number' => 'SCALE-'.$i, 'document_type' => 'vat_invoice', 'issue_date' => '2026-10-16', 'status' => 'issued']);
        }
        $this->withToken($token)->getJson('/api/merchant/sales-reports?from=2026-10-01&to=2026-10-31')->assertStatus(503);
        while (app(LedgerProjection::class)->refresh()) {}
        $this->getJson('/api/merchant/sales-reports?from=2026-10-01&to=2026-10-31&view=review&page=2')->assertOk()->assertJsonPath('excluded_count', 2001)->assertJsonCount(10, 'data');
        $this->getJson('/api/merchant/sales-reports/export?from=2026-10-01&to=2026-10-31&format=pdf')->assertUnprocessable();
    }
}
