<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientWalletBalance;
use App\Http\Controllers\Controller;
use App\Services\ApiCustomerReceipt;
use App\Services\AtomicQuota;
use App\Services\BirCompliance;
use App\Services\EisDocumentReadiness;
use App\Services\IntegrationContract;
use App\Services\IntegrationSourceProfile;
use App\Services\IntegrationStatus;
use App\Services\InvoiceCalculator;
use App\Services\InvoiceSequence;
use App\Services\IssuedDocumentLedger;
use App\Services\MerchantWallet;
use App\Services\MerchantWebhooks;
use App\Services\SandboxTransactions;
use App\Services\SignedWebhook;
use App\Services\StatutorySales;
use App\Services\TransactionPricing;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IntegrationTransactionController extends Controller
{
    private function authenticate(Request $r, string $scope): array
    {
        $raw = (string) ($r->header('X-NUERS-API-Key') ?: $r->bearerToken());
        abort_if($r->header('X-NUERS-API-Key') && $r->bearerToken() && ! hash_equals((string) $r->header('X-NUERS-API-Key'), (string) $r->bearerToken()), 401, 'Conflicting credentials. Supply one API key.');
        abort_if(! $r->isMethod('GET') && ! $r->isJson(), 415, 'Send application/json.');
        abort_if(! $raw || strlen($raw) > 256, 401, 'Missing or invalid API key.');
        $key = DB::table('merchant_api_keys')->where('key_hash', hash('sha256', $raw))->first();
        abort_unless($key, 401, 'Invalid API key.');
        abort_unless($key->status === 'active' && ($key->expires_at && now()->lt($key->expires_at)), 403, 'API key is inactive or expired.');
        abort_unless(in_array($scope, json_decode($key->scopes ?? '[]', true) ?: [], true), 403, 'API key lacks the required scope.');
        $ips = json_decode($key->ip_whitelist ?? '[]', true) ?: [];
        abort_if($ips && ! in_array($r->ip(), $ips, true), 403, 'IP is not authorized.');
        $merchant = DB::table('merchants')->where('id', $key->merchant_id)->first();
        abort_unless($merchant, 404);
        if (! $r->isMethod('GET')) {
            abort_unless($merchant->status === 'active', 403, 'Only active businesses can submit new financial events. Retrieval remains available.');
        }
        app(AtomicQuota::class)->admit('integration:'.$key->id, (int) $key->rate_limit_per_day);

        return [$key, $merchant];
    }

    public function show(Request $r, string $id)
    {
        [$key,$merchant] = $this->authenticate($r, 'transactions:read');
        if ($key->environment === 'sandbox') {
            return response()->json(app(SandboxTransactions::class)->show($key, $id))->header('Cache-Control', 'private, no-store');
        }
        $inbox = DB::table('integration_inbox')->where('id', $id)->where('merchant_id', $merchant->id)->where('branch_code', $key->branch_code)->first();
        abort_unless($inbox, 404);
        $response = json_decode($inbox->response, true);
        $response['customer_receipt'] = app(ApiCustomerReceipt::class)->status((string) $merchant->id, (string) $inbox->receipt_id);
        $response['environment'] = 'live';

        return response()->json($response)->header('Cache-Control', 'no-store');
    }

    private function accept(array $v, object $key, object $merchant): array
    {
        abort_unless(preg_match('/^[0-9]{5}$/', (string) $key->branch_code) && $key->branch_code === $v['branch_code'], 403, 'API key must be bound to this verified five-digit BIR branch.');
        $family = in_array($v['document_type'], ['payment_receipt', 'official_receipt'], true) ? 'payment' : 'invoice';
        abort_unless($v['event_type'] === ($family === 'payment' ? 'payment.recorded' : 'invoice.finalized'), 422, 'Event trigger and document family conflict.');
        $hash = app(IntegrationContract::class)->canonicalHash($v);
        $namespace = hash('sha256', json_encode([$merchant->id, $v['branch_code'], $v['source_system'], $family, $v['external_reference']]));
        $eventHash = hash('sha256', json_encode([$merchant->id, $v['branch_code'], $v['source_system'], $v['source_event_id']]));

        return DB::transaction(function () use ($v, $hash, $namespace, $eventHash, $merchant, $key, $family) {
            // Serialize new source acceptance per tenant; the unique indexes are a second independent guard.
            $merchant = DB::table('merchants')->where('id', $merchant->id)->lockForUpdate()->first();
            abort_unless($merchant && $merchant->status === 'active', 403, 'Only active businesses can accept new financial events.');
            $currentKey = DB::table('merchant_api_keys')->where('id', $key->id)->lockForUpdate()->first();
            abort_unless($currentKey && $currentKey->environment === 'live' && $currentKey->branch_code === $key->branch_code && $currentKey->ip_whitelist === $key->ip_whitelist && $currentKey->status === 'active' && $currentKey->expires_at && now()->lt($currentKey->expires_at) && in_array('transactions:write', json_decode($currentKey->scopes ?? '[]', true) ?: [], true), 403, 'API key policy changed before acceptance.');
            $old = DB::table('integration_inbox')->where('namespace_hash', $namespace)->orWhere('event_hash', $eventHash)->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Source identity was reused with different content. Preserve the original; submit a lawful correction.');

                return app(IntegrationStatus::class)->accepted(['duplicate' => true, ...json_decode($old->response, true), 'environment' => 'live', 'customer_receipt' => app(ApiCustomerReceipt::class)->status((string) $merchant->id, (string) $old->receipt_id)]);
            }
            $profile = app(IntegrationSourceProfile::class)->forEvent($v, $merchant);
            $sellerVat = $profile ? (bool) $profile->vat_registered : (bool) $merchant->vat_registered;
            $tax = app(IntegrationContract::class)->calculateDocument($v, $sellerVat);
            $resolved = app(IntegrationContract::class)->resolveDocument($v, $tax);
            $type = $resolved['type'];
            $id = (string) Str::uuid();
            $transaction = (string) Str::uuid();
            $receipt = (string) Str::uuid();
            $wallet = app(MerchantWallet::class)->charge((string) $merchant->id, app(InvoiceCalculator::class)->cents($tax['gross_amount'], 'gross_amount'), $type, 'receipt', $receipt, $v['receipt_number'], $v['branch_code'], 'api', 'api-key:'.$key->id);
            $authority = $profile ? [] : app(BirCompliance::class)->assertCanIssue((string) $merchant->id, $v['branch_code'], 'production');
            $series = $profile ? ['number' => $v['receipt_number'], 'series_id' => null] : app(InvoiceSequence::class)->reserve((string) $merchant->id, $v['branch_code'], $family, $v['receipt_number']);
            $at = CarbonImmutable::parse($v['occurred_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            $this->assertNumberAvailable((string) $merchant->id, $v['branch_code'], $family, $series['number']);
            DB::table('merchant_transactions')->insert([
                'id' => $transaction, 'merchant_id' => $merchant->id, 'merchant_ref_id' => $merchant->tin, 'transaction_ref' => 'SRC-'.$namespace,
                'source_request_hash' => $hash, 'source_system' => $v['source_system'], 'source_api_key_id' => $key->id,
                'amount' => $tax['gross_amount'], 'vat_amount' => $tax['vat_amount'], 'vatable_sales' => $tax['vatable_sales'], 'net_amount' => $tax['net_amount'],
                'payment_method' => $v['payment_method'], 'region' => $merchant->region, 'branch' => $key->branch_name, 'branch_code' => $v['branch_code'], 'branch_type' => $key->branch_type,
                'channel' => 'api', 'transaction_type' => $family === 'invoice' ? 'sale' : 'payment', 'tax_type' => $resolved['tax_type'], 'document_type' => $type,
                'customer_name' => $v['customer_name'] ?? null, 'customer_tin' => $v['customer_tin'] ?? null, 'status' => 'completed', 'receipt_id' => $receipt,
                'external_payload' => json_encode($v), 'created_at' => $at, 'updated_at' => now(),
            ]);
            $terminals = DB::table('merchant_terminals as mt')->join('merchant_branches as mb', function ($join) {
                $join->on('mb.id', '=', 'mt.branch_id')->on('mb.merchant_id', '=', 'mt.merchant_id');
            })->where('mt.merchant_id', $merchant->id)->where('mt.api_key_id', $key->id)->where('mt.status', 'active')->where('mb.status', 'active')->where('mb.bir_branch_code', $v['branch_code'])->pluck('mt.id');
            DB::table('transaction_receipts')->insert([
                ...app(StatutorySales::class)->storage($v['sales_benefit'] ?? null, $tax),
                ...$authority, 'issuer_terminal_id' => $terminals->count() === 1 ? $terminals->first() : null, 'id' => $receipt, 'transaction_id' => $transaction, 'merchant_id' => $merchant->id, 'receipt_number' => $series['number'], 'series_number' => $series['series_id'],
                'record_mode' => $profile ? 'source_capture' : 'nuers_issuance', 'source_profile_id' => $profile?->id, 'receipt_type' => $profile ? 'api_source_copy' : 'api_transaction_receipt', 'document_type' => $type, 'merchant_name' => $profile?->seller_name ?? $merchant->business_name, 'merchant_tin' => $profile?->seller_tin ?? $merchant->tin,
                'merchant_address' => $profile?->seller_address ?? $merchant->address ?? '', 'merchant_vat_reg' => $sellerVat ? 'VAT Registered' : 'Non-VAT',
                'seller_branch_code' => $v['branch_code'], 'buyer_email' => $v['customer_email'] ?? null, 'buyer_name' => $v['customer_name'] ?? null, 'buyer_tin' => $v['customer_tin'] ?? null, 'buyer_address' => $v['customer_address'] ?? null, 'withholding_tax' => $tax['withholding_tax'], 'withholding_basis_reference' => $v['withholding_basis_reference'] ?? null, 'sspt_sales' => $tax['sspt_sales'],
                'gross_amount' => $tax['subtotal_amount'], 'discount_amount' => $tax['discount_amount'], 'vatable_sales' => $tax['vatable_sales'], 'vat_exempt_sales' => $tax['vat_exempt_sales'],
                'zero_rated_sales' => $tax['zero_rated_sales'], 'vat_amount' => $tax['vat_amount'], 'total_due' => $tax['gross_amount'],
                'items' => json_encode(array_map(fn ($i) => $i + ['vat_inclusive' => (bool) ($v['vat_inclusive'] ?? true)], $tax['items'])),
                'status' => 'issued', 'issued_at' => $at, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $prep = app(EisDocumentReadiness::class)->prepare('receipt', $receipt);
            $body = ['environment' => 'live', 'id' => $id, 'transaction_id' => $transaction, 'receipt_id' => $receipt, 'receipt_number' => $series['number'], 'external_reference' => $v['external_reference'],
                'record_mode' => $profile ? 'source_capture' : 'nuers_issuance', 'source_profile_id' => $profile?->id, 'calculation' => $tax, 'source_event_id' => $v['source_event_id'], 'occurred_at' => $v['occurred_at'], 'accepted_at' => now()->toIso8601String(), 'status' => 'durably_recorded',
                'wallet' => $wallet, 'outbox_id' => $prep['id'], 'status_url' => url('/api/integrations/transactions/'.$id)];
            $body['customer_receipt'] = app(ApiCustomerReceipt::class)->queue($merchant, $id, $receipt, $v['customer_email'] ?? null);
            $body = app(IntegrationStatus::class)->accepted($body);
            DB::table('integration_inbox')->insert(['id' => $id, 'merchant_id' => $merchant->id, 'api_key_id' => $key->id, 'namespace_hash' => $namespace, 'event_hash' => $eventHash, 'request_hash' => $hash,
                'source_system' => $v['source_system'], 'source_event_id' => $v['source_event_id'], 'external_reference' => $v['external_reference'], 'branch_code' => $v['branch_code'],
                'schema_version' => $v['schema_version'], 'event_type' => $v['event_type'], 'occurred_at' => $at, 'received_at' => now(), 'transaction_id' => $transaction, 'receipt_id' => $receipt,
                'response' => json_encode($body), 'created_at' => now(), 'updated_at' => now()]);
            app(BirCompliance::class)->audit('integration.event.accepted', $id, 'api-key:'.$key->id, ['merchant_id' => $merchant->id, 'source_hash' => $hash, 'series_id' => $series['series_id']]);
            app(MerchantWebhooks::class)->accepted((string) $merchant->id, 'live', $id, $body, $profile ? 'source.received' : ($family === 'payment' ? 'payment.recorded' : 'invoice.issued'));
            app(SignedWebhook::class)->enqueue((string) $merchant->id, $profile ? 'source.received' : ($family === 'payment' ? 'payment.recorded' : 'invoice.issued'), $receipt, ['status_url' => $body['status_url']]);

            return ['duplicate' => false, ...$body];
        }, 3);
    }

    private function assertNumberAvailable(string $merchant, string $branch, string $family, string $number): void
    {
        $exists = DB::query()->fromSub(app(IssuedDocumentLedger::class)->normalizedCandidates(), 'source_identity')->where('merchant_id', $merchant)
            ->where('seller_branch_key', $branch)->where('document_family', $family === 'payment' ? 'receipt' : 'invoice')
            ->where('document_number_key', strtoupper(trim($number)))->exists();
        abort_if($exists, 409, 'This seller, branch and document number already exists. Reconcile the original instead of recording a second copy.');
    }

    public function wallet(Request $r)
    {
        [$key,$merchant] = $this->authenticate($r, 'transactions:read');
        if ($key->environment === 'sandbox') {
            $p = app(TransactionPricing::class)->offer();

            return response()->json(['environment' => 'sandbox', 'test_only' => true, 'merchant_id' => $merchant->id, 'branch_code' => $key->branch_code, 'currency' => 'PHP', 'available_centavos' => null, 'intake_mode' => 'batch', 'pricing' => ['fee_centavos' => 0, 'simulated_fee_centavos' => (int) $p->fee_centavos, 'minimum_transaction_centavos' => (int) $p->minimum_transaction_centavos, 'revision' => (int) $p->revision], 'notice' => 'Sandbox does not read or debit the live wallet.'])->header('Cache-Control', 'private, no-store');
        }
        $w = app(MerchantWallet::class)->state($merchant->id);
        $p = app(TransactionPricing::class)->offer();

        return response()->json(['environment' => 'live', 'merchant_id' => $merchant->id, 'branch_code' => $key->branch_code, 'currency' => 'PHP', 'available_centavos' => (int) $w->available_centavos, 'intake_mode' => $w->intake_mode, 'pricing' => ['fee_centavos' => $p->fee_centavos, 'minimum_transaction_centavos' => $p->minimum_transaction_centavos, 'revision' => $p->revision], 'funding_url' => url('/merchant/wallet')])->header('Cache-Control', 'private, no-store');
    }

    public function batch(Request $r)
    {
        $started = microtime(true);
        $key = null;
        $merchant = null;
        $status = 500;
        try {
            [$key,$merchant] = $this->authenticate($r, 'transactions:write');
            // Sandbox acceptance uses its physically separate store below.
            $v = $r->validate(['events' => 'required|array|min:1|max:50']);
            $events = [];
            foreach ($v['events'] as $event) {
                abort_unless(is_array($event), 422, 'Each event must be a JSON object.');
                $events[] = app(IntegrationContract::class)->validateEvent($event);
            }
            if ($key->environment === 'sandbox') {
                $results = DB::transaction(function () use ($events, $key, $merchant) {
                    $rows = [];
                    foreach ($events as $event) {
                        $rows[] = app(SandboxTransactions::class)->accept($event, $key, $merchant);
                    }

return $rows;
                }, 3);
                $status = 201;

                return response()->json(['environment' => 'sandbox', 'test_only' => true, 'atomic' => true, 'accepted' => count($results), 'results' => $results], 201)->header('Cache-Control', 'private, no-store');
            }
            $results = DB::transaction(function () use ($events, $key, $merchant) {
                DB::table('merchants')->where('id', $merchant->id)->lockForUpdate()->first();
                $p = app(TransactionPricing::class)->offer(true);
                $required = 0;
                $seen = [];
                foreach ($events as $event) {
                    abort_unless($event['branch_code'] === $key->branch_code, 403, 'API key does not authorize this batch branch.');
                    $family = in_array($event['document_type'], ['payment_receipt', 'official_receipt'], true) ? 'payment' : 'invoice';
                    $namespace = hash('sha256', json_encode([$merchant->id, $event['branch_code'], $event['source_system'], $family, $event['external_reference']]));
                    $eventHash = hash('sha256', json_encode([$merchant->id, $event['branch_code'], $event['source_system'], $event['source_event_id']]));
                    $hash = app(IntegrationContract::class)->canonicalHash($event);
                    $old = DB::table('integration_inbox')->where('namespace_hash', $namespace)->orWhere('event_hash', $eventHash)->first();
                    if ($old) {
                        abort_unless(hash_equals($old->request_hash, $hash), 409, 'A batch source identity conflicts with an accepted original.');

                        continue;
                    }
                    foreach ([$namespace, $eventHash] as $identity) {
                        if (isset($seen[$identity])) {
                            abort_unless($seen[$identity] === $hash, 409, 'A batch contains conflicting source identities.');
                        }
                    }
                    $duplicate = isset($seen[$namespace]) || isset($seen[$eventHash]);
                    $seen[$namespace] = $hash;
                    $seen[$eventHash] = $hash;
                    $profile = app(IntegrationSourceProfile::class)->forEvent($event, $merchant);
                    $tax = app(IntegrationContract::class)->calculateDocument($event, $profile ? (bool) $profile->vat_registered : (bool) $merchant->vat_registered);
                    if (! $duplicate && $family === 'invoice' && app(InvoiceCalculator::class)->cents($tax['gross_amount'], 'gross_amount') >= $p->minimum_transaction_centavos) {
                        $required += (int) $p->fee_centavos;
                    }
                }
                if ($required) {
                    $w = app(MerchantWallet::class)->locked($merchant->id);
                    if ($w->available_centavos < $required) {
                        throw new InsufficientWalletBalance((int) $w->available_centavos, $required);
                    }
                }
                $result = [];
                foreach ($events as $event) {
                    $result[] = $this->accept($event, $key, $merchant);
                }

                return $result;
            }, 3);

            $status = 201;

            return response()->json(['environment' => 'live', 'atomic' => true, 'accepted' => count($results), 'results' => $results], 201)->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            $status = $e instanceof ValidationException ? 422 : (method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500);
            throw $e;
        } finally {
            $this->logRequest($r, $key, $merchant, $status, $started, '/api/integrations/transactions/batch');
        }
    }

    public function store(Request $r)
    {
        $started = microtime(true);
        $key = null;
        $merchant = null;
        $status = 500;
        try {
            [$key,$merchant] = $this->authenticate($r, 'transactions:write');

            $v = app(IntegrationContract::class)->validateEvent($r->all());
            $result = $key->environment === 'sandbox' ? app(SandboxTransactions::class)->accept($v, $key, $merchant) : $this->accept($v, $key, $merchant);
            $status = $result['duplicate'] ? 200 : 201;

            return response()->json($result, $status)->header('Location', $result['status_url']);
        } catch (\Throwable $e) {
            $status = $e instanceof ValidationException ? 422 : (method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500);
            throw $e;
        } finally {
            $this->logRequest($r, $key, $merchant, $status, $started, '/api/integrations/transactions');
        }
    }

    private function logRequest(Request $r, ?object $key, ?object $merchant, int $status, float $started, string $endpoint): void
    {
        // Best effort telemetry cannot turn a successfully committed acceptance into a false failure.
        try {
            DB::transaction(function () use ($r, $key, $merchant, $status, $started, $endpoint) {
                // FK checks on telemetry also lock parents. Match financial admission's business -> key order.
                if ($merchant) {
                    DB::table('merchants')->where('id', $merchant->id)->lockForUpdate()->first();
                }
                if ($key) {
                    DB::table('merchant_api_keys')->where('id', $key->id)->lockForUpdate()->first();
                }
                DB::table('merchant_api_request_logs')->insert(['id' => (string) Str::uuid(), 'merchant_api_key_id' => $key?->id, 'merchant_id' => $merchant?->id,
                    'endpoint' => $endpoint, 'method' => 'POST', 'status_code' => $status, 'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                    'request_reference' => mb_substr((string) ($r->input('external_reference') ?? ($r->is('api/integrations/transactions/batch') ? 'batch' : null)), 0, 120), 'source_system' => mb_substr((string) $r->input('source_system'), 0, 120),
                    'ip_address' => $r->ip(), 'created_at' => now(), 'updated_at' => now()]);
                if ($key) {
                    DB::table('merchant_api_keys')->where('id', $key->id)->update(['last_used_at' => now()]);
                }
            }, 3);
        } catch (\Throwable $loggingError) {
            report($loggingError);
        }
    }
}
