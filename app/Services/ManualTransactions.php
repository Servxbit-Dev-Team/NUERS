<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Portal-entered financial records use the receipt ledger without claiming BIR issuance authority. */
class ManualTransactions
{
    public function record(Request $request, object $merchant, array $v, array $totals, string $hash): array
    {
        return DB::transaction(function () use ($request, $merchant, $v, $totals, $hash) {
            $merchant = DB::table('merchants')->where('id', $merchant->id)->lockForUpdate()->first();
            app(MerchantTeamAccess::class)->recheckMutation($request);
            abort_unless($merchant && $merchant->status === 'active', 403, 'Only active businesses can record transactions.');
            $actor = (string) $request->attributes->get('api_actor')->id;
            $reference = 'MANUAL-'.$v['request_id'];
            $old = DB::table('merchant_transactions')->where('merchant_id', $merchant->id)->where('transaction_ref', $reference)->first();
            if ($old) {
                abort_unless($old->source_system === 'nuers-portal' && hash_equals((string) $old->source_request_hash, $hash), 409, 'This save identifier already belongs to a different transaction. Open the saved record before retrying.');
                $body = json_decode($old->external_payload, true, flags: JSON_THROW_ON_ERROR)['response'];

                return [...$body, 'duplicate' => true, 'customer_receipt' => app(ApiCustomerReceipt::class)->status((string) $merchant->id, $old->receipt_id, 'manual_customer_receipt')];
            }
            abort_if(DB::table('invoice_create_requests')->where('merchant_id', $merchant->id)->where('request_id', $v['request_id'])->exists(), 409, 'This save identifier already belongs to a saved invoice. Start a new transaction.');
            app(IndustrySalesBenefits::class)->assertAllowed($merchant, $v['sales_benefit'] ?? null);
            app(BirCompliance::class)->assertSourceCaptureAllowed();
            app(MerchantInvoiceSettings::class)->assertPolicy($merchant, $v['line_items'], $v['buyer_tin'] ?? null);
            app(InvoiceDocumentRules::class)->assertBuyer($v['buyer_customer_type'] ?? null, $v['buyer_name'], $v['buyer_tin'] ?? null, $v['buyer_address'] ?? null);
            if (!empty($v['buyer_phone'])) {
                $length = strlen(preg_replace('/\D/', '', $v['buyer_phone']));
                abort_unless($length >= 7 && $length <= 15, 422, 'Buyer phone must contain between seven and fifteen digits.');
            }
            $branch = $this->branch($merchant);
            $wallet = app(MerchantWallet::class);
            $gross = app(InvoiceCalculator::class)->cents($totals['total_amount'], 'total_amount');
            $wallet->check((string) $merchant->id, $gross, $v['document_type']);
            $customer = app(MerchantCustomers::class)->resolveInvoiceBuyer((string) $merchant->id, $actor, $v);
            $transaction = (string) Str::uuid();
            $receipt = (string) Str::uuid();
            // This reference identifies an internal record; it never consumes an authorized tax series.
            $number = 'MT-'.Str::ulid();
            $at = CarbonImmutable::parse($v['issue_date'], 'Asia/Manila')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            $charge = $wallet->charge((string) $merchant->id, $gross, $v['document_type'], 'receipt', $receipt, $number, $branch, 'manual', $actor);
            $items = array_map(function ($item) use ($v) {
                foreach (['unit_price', 'discount_amount', 'taxable_amount', 'vat_amount', 'line_total'] as $field) {
                    $item[$field] = ($item[$field] ?? 0) / 100;
                }
                return [...$item, 'vat_inclusive' => $v['prices_include_vat'] ?? true];
            }, $totals['_items']);
            $nonVat = $merchant->vat_registered ? 0 : $totals['vat_exempt_amount'];
            $taxTypes = array_unique(array_column($items, 'tax_type'));
            $payload = [...$v, 'customer_type' => $v['buyer_customer_type'] ?? $customer['customer']['type'], 'actor_id' => $actor,
                'reference_number' => $v['reference_number'] ?? null, 'amount_paid' => $totals['amount_paid'], 'amount_due' => $totals['amount_due'],
                'presentation_settings' => app(MerchantInvoiceSettings::class)->snapshot($merchant)];
            DB::table('merchant_transactions')->insert([
                'id' => $transaction, 'merchant_id' => $merchant->id, 'merchant_ref_id' => $merchant->tin,
                'transaction_ref' => $reference, 'source_request_hash' => $hash, 'source_system' => 'nuers-portal',
                'amount' => $totals['total_amount'], 'vat_amount' => $totals['vat_amount'], 'vatable_sales' => $totals['taxable_amount'],
                'net_amount' => $totals['total_amount'] - $totals['vat_amount'], 'payment_method' => $v['payment_method'] ?? 'not_recorded',
                'region' => $merchant->region ?: 'Not recorded', 'branch' => $branch, 'branch_code' => $branch,
                'branch_type' => $branch === '00000' ? 'main' : 'branch', 'channel' => 'manual',
                'transaction_type' => in_array($v['document_type'], ['official_receipt', 'payment_receipt'], true) ? 'payment' : 'sale',
                'document_type' => $v['document_type'], 'tax_type' => count($taxTypes) === 1 ? reset($taxTypes) : 'mixed', 'customer_name' => $v['buyer_name'], 'customer_tin' => $v['buyer_tin'] ?? null,
                'status' => 'completed', 'receipt_id' => $receipt, 'external_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('transaction_receipts')->insert([
                ...app(StatutorySales::class)->storage($v['sales_benefit'] ?? null, $totals),
                'id' => $receipt, 'transaction_id' => $transaction, 'merchant_id' => $merchant->id, 'receipt_number' => $number,
                'receipt_type' => 'manual_transaction', 'document_type' => $v['document_type'], 'record_mode' => 'manual_entry',
                'merchant_name' => $merchant->business_name, 'merchant_tin' => $merchant->tin,
                'merchant_address' => $merchant->business_registration_address ?: $merchant->address,
                'merchant_vat_reg' => $merchant->vat_registered ? 'VAT Registered' : 'Non-VAT', 'seller_branch_code' => $branch, 'rdo_code' => $merchant->rdo_code,
                'buyer_name' => $v['buyer_name'], 'buyer_tin' => $v['buyer_tin'] ?? null, 'buyer_address' => $v['buyer_address'] ?? null, 'buyer_email' => $v['buyer_email'] ?? null,
                'gross_amount' => $totals['subtotal_amount'], 'discount_amount' => $totals['discount_amount'],
                'vatable_sales' => $totals['taxable_amount'], 'vat_exempt_sales' => $totals['vat_exempt_amount'] - $nonVat, 'sspt_sales' => $nonVat,
                'zero_rated_sales' => $totals['zero_rated_amount'], 'vat_amount' => $totals['vat_amount'], 'withholding_tax' => $totals['withholding_tax'],
                'withholding_basis_reference' => $v['withholding_details']['evidence_reference'] ?? null,
                'total_due' => $totals['total_amount'], 'items' => json_encode($items, JSON_THROW_ON_ERROR),
                'status' => 'issued', 'issued_at' => $at, 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(MerchantCustomers::class)->link((string) $merchant->id, $customer['customer']['id'], $actor, 'receipt', $receipt);
            if (!empty($v['manual_recovery_id'])) {
                app(ComplianceOperations::class)->linkManual((string) $merchant->id, $v['manual_recovery_id'], 'receipt', $receipt, $actor);
            }
            $prepared = app(EisDocumentReadiness::class)->prepare('receipt', $receipt);
            $email = app(ApiCustomerReceipt::class)->queue($merchant, $v['request_id'], $receipt, $v['buyer_email'] ?? null, 'manual_customer_receipt');
            $body = app(IntegrationStatus::class)->accepted([
                'request_id' => $v['request_id'], 'duplicate' => false, 'environment' => 'live', 'record_mode' => 'manual_entry',
                'transaction_id' => $transaction, 'receipt_id' => $receipt, 'receipt_number' => $number, 'status' => 'durably_recorded',
                'wallet' => $charge, 'customer_receipt' => $email, 'outbox_id' => $prepared['id'],
                'customer' => ['id' => $customer['customer']['id'], 'code' => $customer['customer']['code'], 'created' => $customer['created']],
            ]);
            DB::table('merchant_transactions')->where('id', $transaction)->update(['external_payload' => json_encode([...$payload, 'response' => $body], JSON_THROW_ON_ERROR)]);
            app(BirCompliance::class)->audit('manual.transaction.recorded', $transaction, $actor, ['merchant_id' => $merchant->id, 'receipt_id' => $receipt, 'request_hash' => $hash, 'channel' => 'manual']);

            return $body;
        }, 3);
    }

    private function branch(object $merchant): string
    {
        $identity = app(SellerIdentity::class)->parse($merchant->tin, null);
        abort_unless($identity['valid'], 422, 'The registered business TIN needs review before saving a transaction.');
        $branches = DB::table('merchant_branches')->where('merchant_id', $merchant->id);
        $code = $identity['branch_code'];
        if (!$code) {
            $main = (clone $branches)->where('branch_type', 'main')->where('status', 'active')->whereNotNull('bir_branch_code')->pluck('bir_branch_code');
            abort_unless($main->count() === 1, 422, 'Confirm the registered branch code in Business Profile before saving a transaction.');
            $code = $main->first();
        }
        abort_if((clone $branches)->where('bir_branch_code', $code)->where('status', '!=', 'active')->exists(), 422, 'The registered business location is inactive.');

        return $code;
    }
}
