<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BirCompliance;
use App\Services\IntegrationContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IntegrationTransactionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $started = microtime(true);
        $apiKey = null;
        $merchant = null;
        $statusCode = 201;
        $error = null;

        try {
            [$apiKey, $merchant] = $this->authenticateApiKey($request);
            $this->enforceScope($apiKey, 'transactions:write');
            $this->enforceRateLimit($apiKey);

            $validated = $request->validate(app(IntegrationContract::class)->rules());

            $this->resolveCustomerTinFromBarcode($validated);

            $reference = $validated['external_reference'];
            $existing = DB::table('merchant_transactions')
                ->where('merchant_id', $merchant->id)
                ->where('transaction_ref', $reference)
                ->first();

            if ($existing) {
                $statusCode = 200;

                return response()->json([
                    'duplicate' => true,
                    'transaction_id' => $existing->id,
                    'message' => 'Transaction already exists for this Business Account.',
                ]);
            }

            $authority = app(BirCompliance::class)->assertCanIssue((string) $merchant->id);

            $amount = (float) ($validated['gross_amount'] ?? $validated['amount'] ?? 0);
            $tax = app(IntegrationContract::class)->computeTax($amount, $validated['tax_type'], (float) ($validated['vat_rate'] ?? 12), (bool) ($validated['vat_inclusive'] ?? true));
            $transactionId = (string) Str::uuid();
            $receiptId = (string) Str::uuid();
            $documentType = $this->resolveDocumentType($validated, $merchant, $tax);
            $templateCode = $this->templateCode($documentType);
            $receiptNumber = $validated['receipt_number'] ?? $this->receiptNumber($documentType);
            $now = now();

            $branch = $this->branchIdentity($apiKey, $merchant, $validated);

            DB::transaction(function () use ($merchant, $apiKey, $validated, $tax, $transactionId, $receiptId, $receiptNumber, $documentType, $templateCode, $now, $branch, $authority) {
                $transaction = [
                    'id' => $transactionId,
                    'merchant_id' => $merchant->id,
                    'merchant_ref_id' => $merchant->tin,
                    'transaction_ref' => $validated['external_reference'],
                    'amount' => $tax['gross_amount'],
                    'vat_amount' => $tax['vat_amount'],
                    'vatable_sales' => $tax['vatable_sales'],
                    'net_amount' => $tax['net_amount'],
                    'payment_method' => $validated['payment_method'] ?? 'cash',
                    'region' => $merchant->region ?: 'NCR',
                    'branch' => $branch['name'],
                    'channel' => $validated['channel'] ?? 'api',
                    'transaction_type' => $validated['transaction_type'] ?? 'sale',
                    'customer_name' => $validated['customer_name'] ?? null,
                    'customer_tin' => $validated['customer_tin'] ?? null,
                    'status' => 'completed',
                    'receipt_id' => $receiptId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (Schema::hasColumn('merchant_transactions', 'source_system')) {
                    $transaction['source_system'] = $validated['source_system'] ?? 'external_api';
                }

                if (Schema::hasColumn('merchant_transactions', 'source_api_key_id')) {
                    $transaction['source_api_key_id'] = $apiKey->id;
                }

                if (Schema::hasColumn('merchant_transactions', 'branch_code')) {
                    $transaction['branch_code'] = $branch['code'];
                }

                if (Schema::hasColumn('merchant_transactions', 'branch_type')) {
                    $transaction['branch_type'] = $branch['type'];
                }

                if (Schema::hasColumn('merchant_transactions', 'tax_type')) {
                    $transaction['tax_type'] = $validated['tax_type'];
                }

                if (Schema::hasColumn('merchant_transactions', 'document_type')) {
                    $transaction['document_type'] = $documentType;
                }

                if (Schema::hasColumn('merchant_transactions', 'bir_template_code')) {
                    $transaction['bir_template_code'] = $templateCode;
                }

                if (Schema::hasColumn('merchant_transactions', 'external_payload')) {
                    $transaction['external_payload'] = json_encode($validated);
                }

                if (Schema::hasColumn('merchant_transactions', 'rdo_code')) {
                    $transaction['rdo_code'] = $merchant->rdo_code;
                    $transaction['rdo_name'] = $merchant->rdo_name;
                }

                DB::table('merchant_transactions')->insert($transaction);

                $receipt = [
                    ...$authority,
                    'id' => $receiptId,
                    'transaction_id' => $transactionId,
                    'merchant_id' => $merchant->id,
                    'receipt_number' => $receiptNumber,
                    'series_number' => null,
                    'bir_accreditation' => null,
                    'receipt_type' => 'api_transaction_receipt',
                    'document_type' => $documentType,
                    'bir_template_code' => $templateCode,
                    'merchant_name' => $merchant->business_name,
                    'merchant_tin' => $merchant->tin ?: '',
                    'merchant_address' => $this->merchantAddress($merchant),
                    'merchant_vat_reg' => $merchant->vat_registered ? 'VAT Registered' : 'Non-VAT',
                    'buyer_name' => $validated['customer_name'] ?? null,
                    'buyer_tin' => $validated['customer_tin'] ?? null,
                    'gross_amount' => $tax['gross_amount'],
                    'discount_amount' => 0,
                    'vatable_sales' => $tax['vatable_sales'],
                    'vat_exempt_sales' => $tax['vat_exempt_sales'],
                    'zero_rated_sales' => $tax['zero_rated_sales'],
                    'vat_amount' => $tax['vat_amount'],
                    'total_due' => $tax['gross_amount'],
                    'items' => json_encode($validated['items'] ?? []),
                    'status' => 'issued',
                    'issued_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (Schema::hasColumn('transaction_receipts', 'rdo_code')) {
                    $receipt['rdo_code'] = $merchant->rdo_code;
                    $receipt['rdo_name'] = $merchant->rdo_name;
                }

                if (! Schema::hasColumn('transaction_receipts', 'document_type')) {
                    unset($receipt['document_type']);
                }

                if (! Schema::hasColumn('transaction_receipts', 'bir_template_code')) {
                    unset($receipt['bir_template_code']);
                }

                DB::table('transaction_receipts')->insert($receipt);
                app(BirCompliance::class)->audit('invoice.api.issued', $receiptId, 'api-key:'.$apiKey->id, ['merchant_id' => $merchant->id, 'authority' => $authority, 'document_hash' => hash('sha256', json_encode($receipt, JSON_THROW_ON_ERROR))]);
            });

            DB::table('merchant_api_keys')->where('id', $apiKey->id)->update([
                'last_used_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'transaction_id' => $transactionId,
                'receipt_id' => $receiptId,
                'receipt_number' => $receiptNumber,
                'merchant_id' => $merchant->id,
                'branch' => $branch,
                'document' => [
                    'document_type' => $documentType,
                    'bir_template_code' => $templateCode,
                    'label' => $this->documentLabel($documentType),
                ],
                'tax_classification' => [
                    'tax_type' => $validated['tax_type'],
                    'vatable_sales' => $tax['vatable_sales'],
                    'vat_exempt_sales' => $tax['vat_exempt_sales'],
                    'zero_rated_sales' => $tax['zero_rated_sales'],
                    'vat_amount' => $tax['vat_amount'],
                    'gross_amount' => $tax['gross_amount'],
                ],
            ], 201);
        } catch (\Throwable $throwable) {
            $statusCode = method_exists($throwable, 'getStatusCode') ? $throwable->getStatusCode() : 422;
            $error = $throwable->getMessage();

            throw $throwable;
        } finally {
            $this->logRequest($request, $apiKey, $merchant, $statusCode, $started, $error);
        }
    }

    private function authenticateApiKey(Request $request): array
    {
        abort_unless(Schema::hasTable('merchant_api_keys'), 500, 'merchant_api_keys table is not migrated.');

        $plainKey = (string) ($request->header('X-NUERS-API-Key') ?: $request->bearerToken());

        abort_if($plainKey === '', 401, 'Missing NUERS API key.');

        $apiKey = DB::table('merchant_api_keys')
            ->where('key_hash', hash('sha256', $plainKey))
            ->first();

        abort_unless($apiKey, 401, 'Invalid NUERS API key.');
        abort_unless($apiKey->status === 'active', 403, 'This NUERS API key is not active.');

        $allowedIps = $this->jsonArray($apiKey->ip_whitelist);
        abort_if($allowedIps && ! in_array($request->ip(), $allowedIps, true), 403, 'This IP address is not allowed for the API key.');

        $merchant = DB::table('merchants')->where('id', $apiKey->merchant_id)->first();

        abort_unless($merchant, 404, 'Business Account for this API key was not found.');

        return [$apiKey, $merchant];
    }

    private function resolveCustomerTinFromBarcode(array &$payload): void
    {
        $explicitTin = $this->normalizeTin($payload['customer_tin'] ?? null);

        if ($this->isValidTin($explicitTin)) {
            $payload['customer_tin'] = $this->formatTin($explicitTin);

            return;
        }

        $barcodeTin = $this->tinFromNuersBarcode($payload['customer_name'] ?? null);

        if (! $this->isValidTin($barcodeTin)) {
            return;
        }

        $formattedTin = $this->formatTin($barcodeTin);
        $payload['customer_tin'] = $formattedTin;

        $profiles = DB::table('profiles')
            ->whereRaw($this->normalizedTinSql('tin').' = ?', [$barcodeTin])
            ->limit(2)
            ->get();

        if ($profiles->count() === 1 && trim((string) $profiles->first()->full_name) !== '') {
            $payload['customer_name'] = $profiles->first()->full_name;
        }
    }

    private function tinFromNuersBarcode(?string $value): string
    {
        $text = trim((string) $value);

        if ($text === '' || ! Str::contains(Str::upper($text), 'NUERS')) {
            return '';
        }

        return $this->normalizeTin($text);
    }

    private function normalizeTin(?string $tin): string
    {
        return preg_replace('/\D+/', '', (string) $tin) ?: '';
    }

    private function isValidTin(string $tin): bool
    {
        $length = strlen($tin);

        return $length >= 9 && $length <= 12;
    }

    private function formatTin(string $tin): string
    {
        $digits = substr($this->normalizeTin($tin), 0, 12);
        $groups = array_filter([
            substr($digits, 0, 3),
            substr($digits, 3, 3),
            substr($digits, 6, 3),
            substr($digits, 9, 3),
        ], fn (string $group): bool => $group !== '');

        return implode('-', $groups);
    }

    private function normalizedTinSql(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), '-', ''), ' ', ''), '.', ''), '/', '')";
    }

    private function enforceScope(object $apiKey, string $scope): void
    {
        $scopes = $this->jsonArray($apiKey->scopes);

        abort_unless(in_array($scope, $scopes, true), 403, "API key is missing {$scope} permission.");
    }

    private function enforceRateLimit(object $apiKey): void
    {
        if (! Schema::hasTable('merchant_api_request_logs')) {
            return;
        }

        $count = DB::table('merchant_api_request_logs')
            ->where('merchant_api_key_id', $apiKey->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        abort_if($count >= (int) $apiKey->rate_limit_per_day, 429, 'Daily API key rate limit exceeded.');
    }

    private function logRequest(Request $request, ?object $apiKey, ?object $merchant, int $statusCode, float $started, ?string $error): void
    {
        if (! Schema::hasTable('merchant_api_request_logs')) {
            return;
        }

        $log = [
            'id' => (string) Str::uuid(),
            'merchant_api_key_id' => $apiKey?->id,
            'merchant_id' => $merchant?->id,
            'endpoint' => '/api/integrations/transactions',
            'method' => $request->method(),
            'status_code' => $statusCode,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'request_reference' => $request->input('external_reference'),
            'source_system' => $request->input('source_system'),
            'ip_address' => $request->ip(),
            'error_message' => $error,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('merchant_api_request_logs', 'branch_type')) {
            $log['branch_type'] = $apiKey ? ($apiKey->branch_type ?? 'main') : null;
            $log['branch_code'] = $apiKey ? ($apiKey->branch_code ?? 'MAIN') : null;
            $log['branch_name'] = $apiKey ? ($apiKey->branch_name ?? 'Main Office') : null;
        }

        DB::table('merchant_api_request_logs')->insert($log);
    }

    private function resolveDocumentType(array $payload, object $merchant, array $tax): string
    {
        $explicit = $payload['document_type'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return match ($explicit) {
                'sales_invoice' => $this->documentTypeFromTax($payload, $merchant, $tax),
                'official_receipt' => 'payment_receipt',
                default => $explicit,
            };
        }

        $transactionType = strtolower((string) ($payload['transaction_type'] ?? 'sale'));

        if (Str::contains($transactionType, ['payment', 'receipt', 'collection', 'settlement'])) {
            return 'payment_receipt';
        }

        $itemTaxTypes = collect($payload['items'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(fn ($item) => strtolower((string) ($item['tax_type'] ?? '')))
            ->filter()
            ->unique()
            ->values();

        if ($itemTaxTypes->count() > 1) {
            return 'mixed_sales_invoice';
        }

        return $this->documentTypeFromTax($payload, $merchant, $tax);
    }

    private function documentTypeFromTax(array $payload, object $merchant, array $tax): string
    {
        $taxType = strtolower((string) ($payload['tax_type'] ?? 'vatable'));

        return match (true) {
            $taxType === 'zero_rated' || (float) $tax['zero_rated_sales'] > 0 => 'zero_rated_invoice',
            $taxType === 'vat_exempt' || (float) $tax['vat_exempt_sales'] > 0 => 'vat_exempt_invoice',
            $taxType === 'non_vat' || ! (bool) ($merchant->vat_registered ?? false) => 'non_vat_invoice',
            default => 'vat_invoice',
        };
    }

    private function templateCode(string $documentType): string
    {
        return [
            'vat_invoice' => 'B1',
            'non_vat_invoice' => 'B2',
            'vat_exempt_invoice' => 'B3',
            'zero_rated_invoice' => 'B4',
            'mixed_sales_invoice' => 'B5',
            'payment_receipt' => 'B6',
        ][$documentType] ?? 'B1';
    }

    private function documentLabel(string $documentType): string
    {
        return [
            'vat_invoice' => 'B1 VAT Invoice',
            'non_vat_invoice' => 'B2 Non-VAT Invoice',
            'vat_exempt_invoice' => 'B3 VAT-Exempt Sales Invoice',
            'zero_rated_invoice' => 'B4 Zero-Rated Sales Invoice',
            'mixed_sales_invoice' => 'B5 Mixed Sales Invoice',
            'payment_receipt' => 'B6 Payment Receipt',
        ][$documentType] ?? 'B1 VAT Invoice';
    }

    private function receiptNumber(string $documentType): string
    {
        $prefix = [
            'vat_invoice' => 'VI',
            'non_vat_invoice' => 'NVI',
            'vat_exempt_invoice' => 'VEI',
            'zero_rated_invoice' => 'ZRI',
            'mixed_sales_invoice' => 'MSI',
            'payment_receipt' => 'PR',
        ][$documentType] ?? 'VI';

        return $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }

    private function branchIdentity(object $apiKey, object $merchant, array $payload): array
    {
        $keyBranchType = $apiKey->branch_type ?? null;
        $type = in_array($keyBranchType, ['main', 'branch'], true)
            ? $keyBranchType
            : ($payload['branch_type'] ?? 'main');
        $name = $apiKey->branch_name
            ?? $payload['branch_name']
            ?? $payload['branch']
            ?? ($type === 'main' ? 'Main Office' : 'Branch');
        $code = $apiKey->branch_code
            ?? $payload['branch_code']
            ?? ($type === 'main' ? 'MAIN' : $name);

        return [
            'type' => $type,
            'code' => $this->normalizeBranchCode($code),
            'name' => $name,
            'location' => $apiKey->branch_location ?? ($type === 'main' ? $this->merchantAddress($merchant) : null),
        ];
    }

    private function normalizeBranchCode(?string $value): string
    {
        $normalized = preg_replace('/[^A-Z0-9]+/', '-', Str::upper(trim((string) $value)));
        $normalized = trim((string) $normalized, '-');

        return $normalized !== '' ? Str::limit($normalized, 40, '') : 'MAIN';
    }

    private function merchantAddress(object $merchant): string
    {
        $address = trim((string) ($merchant->address ?: ($merchant->business_registration_address ?? '')));

        if ($address !== '') {
            return $address;
        }

        return implode(', ', array_filter([
            $merchant->barangay ?? null,
            $merchant->city ?? null,
            $merchant->country ?? null,
        ]));
    }

    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
