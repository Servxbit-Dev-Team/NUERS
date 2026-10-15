<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BusinessInvoiceController extends Controller
{
    private const DOCUMENT_TYPES = [
        'vat_invoice',
        'non_vat_invoice',
        'vat_exempt_invoice',
        'zero_rated_invoice',
        'mixed_sales_invoice',
        'payment_receipt',
        'sales_invoice',
        'official_receipt',
        'credit_memo',
        'debit_memo',
        'purchase_invoice',
        'delivery_receipt',
        'service_invoice',
    ];

    private const STATUSES = [
        'draft',
        'pending',
        'validated',
        'sent',
        'paid',
        'partially_paid',
        'overdue',
        'cancelled',
        'voided',
        'correction_pending',
    ];

    public function index(Request $request): JsonResponse
    {
        $merchant = $this->currentMerchant($request);

        abort_unless(Schema::hasTable('business_invoices'), 500, 'business_invoices table is not migrated.');

        $query = DB::table('business_invoices')
            ->where('merchant_id', $merchant->id);

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $status = (string) $request->query('status');
            if ($status === 'unpaid') {
                $query->whereIn('status', ['pending', 'validated', 'sent', 'overdue', 'partially_paid']);
            } elseif ($status === 'cancelled') {
                $query->whereIn('status', ['cancelled', 'voided']);
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->filled('type') && $request->query('type') !== 'all') {
            $query->where('document_type', (string) $request->query('type'));
        }

        if ($request->filled('search')) {
            $search = '%'.strtolower((string) $request->query('search')).'%';
            $query->where(function ($inner) use ($search) {
                $inner
                    ->whereRaw('LOWER(invoice_number) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(buyer_name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(COALESCE(buyer_tin, "")) LIKE ?', [$search]);
            });
        }

        $invoices = $query
            ->orderByDesc('issue_date')
            ->orderByDesc('created_at')
            ->limit(300)
            ->get()
            ->map(fn ($invoice) => $this->invoicePayload($invoice, false))
            ->values();

        return response()->json([
            'merchant' => $this->merchantPayload($merchant),
            'summary' => $this->summary($merchant->id),
            'trend' => $this->trend($merchant->id),
            'type_distribution' => $this->typeDistribution($merchant->id),
            'invoices' => $invoices,
        ]);
    }

    public function show(Request $request, string $invoice): JsonResponse
    {
        $merchant = $this->currentMerchant($request);

        abort_unless(Schema::hasTable('business_invoices'), 500, 'business_invoices table is not migrated.');

        $record = DB::table('business_invoices')
            ->where('merchant_id', $merchant->id)
            ->where(function ($query) use ($invoice) {
                $query->where('id', $invoice)->orWhere('invoice_number', $invoice);
            })
            ->first();

        abort_unless($record, 404, 'Invoice was not found for this Business Account.');

        return response()->json([
            'invoice' => $this->invoicePayload($record, true),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = $this->currentMerchant($request);

        abort_unless(Schema::hasTable('business_invoices'), 500, 'business_invoices table is not migrated.');

        $validated = $request->validate([
            'invoice_number' => ['required', 'string', 'max:80'],
            'document_type' => ['required', Rule::in(self::DOCUMENT_TYPES)],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_tin' => ['nullable', 'string', 'max:80'],
            'buyer_address' => ['nullable', 'string'],
            'buyer_email' => ['nullable', 'email', 'max:255'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'delivery_date' => ['nullable', 'date'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'purchase_order' => ['nullable', 'string', 'max:120'],
            'sales_order' => ['nullable', 'string', 'max:120'],
            'original_invoice_id' => ['nullable', 'string', 'max:120'],
            'correction_reason' => ['nullable', 'string'],
            'qr_payload' => ['nullable', 'string'],
            'digital_signature' => ['nullable', 'string'],
            'validation_hash' => ['nullable', 'string', 'max:255'],
            'formats_generated' => ['nullable', 'array'],
            'formats_generated.*' => ['string', 'max:20'],
            'notes' => ['nullable', 'string'],
            'terms_and_conditions' => ['nullable', 'string'],
            'footer_note' => ['nullable', 'string'],
            'line_items' => ['required', 'array', 'min:1'],
            'line_items.*.line_number' => ['required', 'integer', 'min:1'],
            'line_items.*.item_code' => ['nullable', 'string', 'max:80'],
            'line_items.*.description' => ['required', 'string'],
            'line_items.*.unit' => ['nullable', 'string', 'max:40'],
            'line_items.*.quantity' => ['required', 'numeric', 'min:0'],
            'line_items.*.unit_price' => ['required', 'numeric'],
            'line_items.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'line_items.*.discount_amount' => ['nullable', 'numeric'],
            'line_items.*.taxable_amount' => ['nullable', 'numeric'],
            'line_items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'line_items.*.vat_amount' => ['nullable', 'numeric'],
            'line_items.*.line_total' => ['required', 'numeric'],
            'line_items.*.tax_type' => ['nullable', Rule::in(['vatable', 'zero_rated', 'vat_exempt', 'non_vat'])],
        ]);

        abort_if(
            DB::table('business_invoices')
                ->where('merchant_id', $merchant->id)
                ->where('invoice_number', $validated['invoice_number'])
                ->exists(),
            422,
            'This invoice number already exists for your Business Account.'
        );

        $totals = $this->totalsFromRequest($request);
        $documentType = $this->normalizeDocumentType($validated['document_type'], $validated['line_items'], $totals, (bool) ($merchant->vat_registered ?? false));
        $templateCode = $this->templateCode($documentType);
        $now = now();
        $invoiceId = (string) Str::uuid();
        $status = $validated['status'] ?? 'draft';
        $authority = $status !== 'draft' ? app(\App\Services\BirCompliance::class)->assertCanIssue((string) $merchant->id) : ['permit_to_issue_number' => null, 'esp_accreditation_number' => null];

        DB::transaction(function () use ($request, $validated, $merchant, $invoiceId, $status, $totals, $documentType, $templateCode, $now, $authority) {
            $invoiceRow = [
                ...$authority,
                'id' => $invoiceId,
                'merchant_id' => $merchant->id,
                'invoice_number' => $validated['invoice_number'],
                'document_type' => $documentType,
                'status' => $status,
                'version_number' => 1,
                'parent_invoice_id' => null,
                'merchant_name' => $merchant->business_name,
                'merchant_tin' => $merchant->tin,
                'merchant_address' => $this->merchantAddress($merchant),
                'buyer_name' => $validated['buyer_name'],
                'buyer_tin' => $request->input('buyer_tin'),
                'buyer_address' => $request->input('buyer_address'),
                'buyer_email' => $request->input('buyer_email'),
                'issue_date' => $validated['issue_date'],
                'due_date' => $request->input('due_date') ?: null,
                'delivery_date' => $request->input('delivery_date') ?: null,
                'period_start' => $request->input('period_start') ?: null,
                'period_end' => $request->input('period_end') ?: null,
                'subtotal_amount' => $totals['subtotal_amount'],
                'discount_amount' => $totals['discount_amount'],
                'taxable_amount' => $totals['taxable_amount'],
                'vat_amount' => $totals['vat_amount'],
                'withholding_tax' => $totals['withholding_tax'],
                'other_charges' => $totals['other_charges'],
                'total_amount' => $totals['total_amount'],
                'amount_paid' => $totals['amount_paid'],
                'amount_due' => $totals['amount_due'],
                'currency' => 'PHP',
                'vat_registered' => (bool) ($merchant->vat_registered ?? false),
                'vat_rate' => 12,
                'zero_rated_amount' => $totals['zero_rated_amount'],
                'vat_exempt_amount' => $totals['vat_exempt_amount'],
                'reference_number' => $request->input('reference_number'),
                'purchase_order' => $request->input('purchase_order'),
                'sales_order' => $request->input('sales_order'),
                'original_invoice_id' => $request->input('original_invoice_id'),
                'correction_reason' => $request->input('correction_reason'),
                'qr_payload' => $request->input('qr_payload'),
                'digital_signature' => $request->input('digital_signature'),
                'signed_at' => $request->filled('digital_signature') ? $now : null,
                'validated_at' => $request->filled('validation_hash') ? $now : null,
                'validation_hash' => $request->input('validation_hash'),
                'formats_generated' => json_encode($request->input('formats_generated', ['pdf', 'json'])),
                'notes' => $request->input('notes'),
                'terms_and_conditions' => $request->input('terms_and_conditions'),
                'footer_note' => $request->input('footer_note'),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('business_invoices', 'bir_template_code')) {
                $invoiceRow['bir_template_code'] = $templateCode;
            }

            DB::table('business_invoices')->insert($invoiceRow);

            foreach ($validated['line_items'] as $line) {
                DB::table('business_invoice_items')->insert([
                    'id' => (string) Str::uuid(),
                    'invoice_id' => $invoiceId,
                    'merchant_id' => $merchant->id,
                    'line_number' => (int) $line['line_number'],
                    'item_code' => $line['item_code'] ?? null,
                    'description' => $line['description'],
                    'unit' => $line['unit'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $this->fromCentavos($line['unit_price']),
                    'discount_pct' => $line['discount_pct'] ?? 0,
                    'discount_amount' => $this->fromCentavos($line['discount_amount'] ?? 0),
                    'taxable_amount' => $this->fromCentavos($line['taxable_amount'] ?? 0),
                    'vat_rate' => $line['vat_rate'] ?? 12,
                    'vat_amount' => $this->fromCentavos($line['vat_amount'] ?? 0),
                    'line_total' => $this->fromCentavos($line['line_total']),
                    'tax_type' => $line['tax_type'] ?? 'vatable',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $validated['document_type'] = $documentType;
            if ($status !== 'draft') $this->mirrorBuyerExpense($merchant, $invoiceId, $validated, $totals, $now);
            app(\App\Services\BirCompliance::class)->audit($status === 'draft' ? 'invoice.draft.saved' : 'invoice.issued', $invoiceId, (string) DB::table('users')->where('api_token', $request->bearerToken())->value('id'), ['status' => $status, 'authority' => $authority, 'document_hash' => hash('sha256', json_encode([$invoiceRow, $validated['line_items']], JSON_THROW_ON_ERROR))]);
        });

        $invoice = DB::table('business_invoices')->where('id', $invoiceId)->first();

        return response()->json([
            'invoice' => $this->invoicePayload($invoice, true),
        ], 201);
    }

    private function currentMerchant(Request $request): object
    {
        $token = $request->bearerToken();

        abort_unless($token, 401, 'Authenticated Business Account session is required.');

        $user = DB::table('users')->where('api_token', $token)->first();

        abort_unless($user, 401, 'Authenticated Business Account session is required.');

        $merchant = DB::table('merchants')
            ->where(function ($query) use ($user) {
                $query
                    ->where('merchant_account_id', $user->id)
                    ->orWhere('merchant_account_email', $user->email)
                    ->orWhere('email', $user->email);
            })
            ->orderByRaw(
                'CASE WHEN merchant_account_id = ? THEN 0 WHEN merchant_account_email = ? THEN 1 WHEN email = ? THEN 2 ELSE 3 END',
                [$user->id, $user->email, $user->email],
            )
            ->orderByDesc('updated_at')
            ->first();

        abort_unless($merchant, 404, 'No Business Account registry record is linked to this login.');

        return $merchant;
    }

    private function summary(string $merchantId): array
    {
        $base = DB::table('business_invoices')->where('merchant_id', $merchantId);

        $issued = (clone $base)->sum('total_amount');
        $collected = (clone $base)->where('status', 'paid')->sum('amount_paid');
        $outstanding = (clone $base)
            ->whereIn('status', ['pending', 'validated', 'sent', 'overdue', 'partially_paid'])
            ->sum('amount_due');
        $overdue = (clone $base)->where('status', 'overdue')->count();

        return [
            'invoice_count' => (clone $base)->count(),
            'total_issued' => $this->toCentavos($issued),
            'total_collected' => $this->toCentavos($collected),
            'total_outstanding' => $this->toCentavos($outstanding),
            'total_overdue' => $overdue,
        ];
    }

    private function trend(string $merchantId): array
    {
        return collect(range(5, 0))
            ->map(function ($offset) use ($merchantId) {
                $month = Carbon::now()->subMonths($offset);
                $rows = DB::table('business_invoices')
                    ->where('merchant_id', $merchantId)
                    ->whereYear('issue_date', $month->year)
                    ->whereMonth('issue_date', $month->month);

                return [
                    'month' => $month->format('M'),
                    'issued' => (clone $rows)->count(),
                    'paid' => (clone $rows)->where('status', 'paid')->count(),
                    'overdue' => (clone $rows)->where('status', 'overdue')->count(),
                ];
            })
            ->values()
            ->all();
    }

    private function typeDistribution(string $merchantId): array
    {
        return DB::table('business_invoices')
            ->selectRaw('document_type, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as amount')
            ->where('merchant_id', $merchantId)
            ->groupBy('document_type')
            ->get()
            ->map(fn ($row) => [
                'type' => $this->documentShort((string) $row->document_type),
                'document_type' => $row->document_type,
                'count' => (int) $row->count,
                'amount' => $this->toCentavos($row->amount),
            ])
            ->values()
            ->all();
    }

    private function invoicePayload(object $invoice, bool $withItems): array
    {
        $payload = [
            'id' => (string) $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'document_type' => $invoice->document_type,
            'bir_template_code' => $invoice->bir_template_code ?? $this->templateCode((string) $invoice->document_type),
            'status' => $invoice->status,
            'version_number' => (int) $invoice->version_number,
            'parent_invoice_id' => $invoice->parent_invoice_id,
            'merchant_name' => $invoice->merchant_name,
            'merchant_tin' => $invoice->merchant_tin ?: '',
            'permit_to_issue_number' => $invoice->permit_to_issue_number ?? null,
            'esp_accreditation_number' => $invoice->esp_accreditation_number ?? null,
            'merchant_address' => $invoice->merchant_address ?: '',
            'buyer_name' => $invoice->buyer_name,
            'buyer_tin' => $invoice->buyer_tin,
            'buyer_address' => $invoice->buyer_address,
            'buyer_email' => $invoice->buyer_email,
            'issue_date' => $invoice->issue_date,
            'due_date' => $invoice->due_date,
            'delivery_date' => $invoice->delivery_date,
            'period_start' => $invoice->period_start,
            'period_end' => $invoice->period_end,
            'subtotal_amount' => $this->toCentavos($invoice->subtotal_amount),
            'discount_amount' => $this->toCentavos($invoice->discount_amount),
            'taxable_amount' => $this->toCentavos($invoice->taxable_amount),
            'vat_amount' => $this->toCentavos($invoice->vat_amount),
            'withholding_tax' => $this->toCentavos($invoice->withholding_tax),
            'other_charges' => $this->toCentavos($invoice->other_charges),
            'total_amount' => $this->toCentavos($invoice->total_amount),
            'amount_paid' => $this->toCentavos($invoice->amount_paid),
            'amount_due' => $this->toCentavos($invoice->amount_due),
            'currency' => $invoice->currency,
            'vat_registered' => (bool) $invoice->vat_registered,
            'vat_rate' => (float) $invoice->vat_rate,
            'zero_rated_amount' => $this->toCentavos($invoice->zero_rated_amount),
            'vat_exempt_amount' => $this->toCentavos($invoice->vat_exempt_amount),
            'reference_number' => $invoice->reference_number,
            'purchase_order' => $invoice->purchase_order,
            'sales_order' => $invoice->sales_order,
            'original_invoice_id' => $invoice->original_invoice_id,
            'correction_reason' => $invoice->correction_reason,
            'qr_payload' => $invoice->qr_payload,
            'digital_signature' => $invoice->digital_signature,
            'signed_at' => $invoice->signed_at,
            'validated_at' => $invoice->validated_at,
            'validation_hash' => $invoice->validation_hash,
            'cancellation_reason' => $invoice->cancellation_reason,
            'cancellation_note' => $invoice->cancellation_note,
            'cancelled_at' => $invoice->cancelled_at,
            'formats_generated' => $this->jsonArray($invoice->formats_generated) ?: ['pdf', 'json'],
            'notes' => $invoice->notes,
            'terms_and_conditions' => $invoice->terms_and_conditions,
            'footer_note' => $invoice->footer_note,
            'created_at' => $invoice->created_at,
            'updated_at' => $invoice->updated_at,
        ];

        if ($withItems) {
            $payload['line_items'] = DB::table('business_invoice_items')
                ->where('invoice_id', $invoice->id)
                ->orderBy('line_number')
                ->get()
                ->map(fn ($line) => [
                    'id' => (string) $line->id,
                    'line_number' => (int) $line->line_number,
                    'item_code' => $line->item_code ?: '',
                    'description' => $line->description,
                    'unit' => $line->unit ?: '',
                    'quantity' => (float) $line->quantity,
                    'unit_price' => $this->toCentavos($line->unit_price),
                    'discount_pct' => (float) $line->discount_pct,
                    'discount_amount' => $this->toCentavos($line->discount_amount),
                    'taxable_amount' => $this->toCentavos($line->taxable_amount),
                    'vat_rate' => (float) $line->vat_rate,
                    'vat_amount' => $this->toCentavos($line->vat_amount),
                    'line_total' => $this->toCentavos($line->line_total),
                    'tax_type' => $line->tax_type,
                ])
                ->values();
        }

        return $payload;
    }

    private function totalsFromRequest(Request $request): array
    {
        $lineItems = collect($request->input('line_items', []));
        $subtotal = $request->input('subtotal_amount', $lineItems->sum('line_total'));
        $discount = $request->input('discount_amount', $lineItems->sum('discount_amount'));
        $taxable = $request->input('taxable_amount', $lineItems->sum('taxable_amount'));
        $vat = $request->input('vat_amount', $lineItems->sum('vat_amount'));
        $withholding = $request->input('withholding_tax', 0);
        $otherCharges = $request->input('other_charges', 0);
        $total = $request->input('total_amount', $subtotal - $discount + $vat + $otherCharges);
        $paid = $request->input('amount_paid', 0);

        return [
            'subtotal_amount' => $this->fromCentavos($subtotal),
            'discount_amount' => $this->fromCentavos($discount),
            'taxable_amount' => $this->fromCentavos($taxable),
            'vat_amount' => $this->fromCentavos($vat),
            'withholding_tax' => $this->fromCentavos($withholding),
            'other_charges' => $this->fromCentavos($otherCharges),
            'total_amount' => $this->fromCentavos($total),
            'amount_paid' => $this->fromCentavos($paid),
            'amount_due' => $this->fromCentavos(max(0, $total - $paid - $withholding)),
            'zero_rated_amount' => $this->fromCentavos($request->input('zero_rated_amount', 0)),
            'vat_exempt_amount' => $this->fromCentavos($request->input('vat_exempt_amount', 0)),
        ];
    }

    private function mirrorBuyerExpense(object $seller, string $invoiceId, array $validated, array $totals, Carbon $now): void
    {
        if (! Schema::hasTable('merchant_expenses')) {
            return;
        }

        $buyerTin = $this->normalizeTin((string) ($validated['buyer_tin'] ?? ''));

        if (strlen($buyerTin) < 9) {
            return;
        }

        $buyer = DB::table('merchants')
            ->whereRaw($this->normalizedTinSql('tin').' = ?', [$buyerTin])
            ->first();

        if (! $buyer) {
            return;
        }

        DB::table('merchant_expenses')->updateOrInsert(
            [
                'source_invoice_reference' => $validated['invoice_number'],
                'buyer_tin' => $buyer->tin,
            ],
            [
                'id' => (string) Str::uuid(),
                'merchant_id' => $buyer->id,
                'supplier_merchant_id' => $seller->id,
                'purchase_order_reference' => $validated['purchase_order'] ?? null,
                'supplier_name' => $seller->business_name,
                'supplier_tin' => $seller->tin,
                'buyer_name' => $validated['buyer_name'],
                'expense_category' => 'B2B Purchase',
                'description' => 'Generated from NUERS Business Invoice '.$validated['invoice_number'],
                'gross_amount' => $totals['total_amount'],
                'vatable_amount' => $totals['taxable_amount'],
                'input_vat_amount' => $totals['vat_amount'],
                'withholding_tax_amount' => $totals['withholding_tax'],
                'net_payable' => max(0, $totals['amount_due']),
                'document_type' => $validated['document_type'],
                'payment_status' => 'unpaid',
                'validation_status' => 'validated',
                'reconciliation_status' => 'matched',
                'claim_status' => 'available',
                'risk_level' => 'low',
                'ai_score' => 92,
                'issued_at' => $validated['issue_date'],
                'due_at' => $validated['due_date'] ?? null,
                'metadata' => json_encode([
                    'source' => 'business_invoices',
                    'business_invoice_id' => $invoiceId,
                    'seller_merchant_id' => $seller->id,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function merchantPayload(object $merchant): array
    {
        return [
            'id' => (string) $merchant->id,
            'business_name' => (string) $merchant->business_name,
            'tin' => $merchant->tin,
            'address' => $this->merchantAddress($merchant),
            'email' => $merchant->email ?: $merchant->merchant_account_email,
            'rdo_code' => $merchant->rdo_code ?? null,
            'rdo_name' => $merchant->rdo_name ?? null,
        ];
    }

    private function merchantAddress(object $merchant): string
    {
        $address = trim((string) ($merchant->address ?: ($merchant->business_registration_address ?? '')));

        if ($address !== '') {
            return $address;
        }

        $parts = array_filter([
            $merchant->barangay ?? null,
            $merchant->city ?? null,
            $merchant->country ?? null,
        ]);

        return $parts ? implode(', ', $parts) : '';
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

    private function documentShort(string $documentType): string
    {
        return [
            'vat_invoice' => 'B1',
            'non_vat_invoice' => 'B2',
            'vat_exempt_invoice' => 'B3',
            'zero_rated_invoice' => 'B4',
            'mixed_sales_invoice' => 'B5',
            'payment_receipt' => 'B6',
            'sales_invoice' => 'SI',
            'official_receipt' => 'OR',
            'credit_memo' => 'CM',
            'debit_memo' => 'DM',
            'purchase_invoice' => 'PI',
            'delivery_receipt' => 'DR',
            'service_invoice' => 'SVI',
        ][$documentType] ?? strtoupper(Str::substr($documentType, 0, 3));
    }

    private function normalizeDocumentType(string $documentType, array $lineItems, array $totals, bool $vatRegistered): string
    {
        if ($documentType === 'official_receipt') {
            return 'payment_receipt';
        }

        if ($documentType !== 'sales_invoice') {
            return $documentType;
        }

        $taxTypes = collect($lineItems)
            ->map(fn ($line) => strtolower((string) ($line['tax_type'] ?? '')))
            ->filter()
            ->unique()
            ->values();

        if ($taxTypes->count() > 1) {
            return 'mixed_sales_invoice';
        }

        $taxType = (string) ($taxTypes->first() ?: 'vatable');

        return match (true) {
            $taxType === 'zero_rated' || $totals['zero_rated_amount'] > 0 => 'zero_rated_invoice',
            $taxType === 'vat_exempt' || $totals['vat_exempt_amount'] > 0 => 'vat_exempt_invoice',
            $taxType === 'non_vat' || ! $vatRegistered => 'non_vat_invoice',
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
            'sales_invoice' => 'B1',
            'official_receipt' => 'B6',
            'service_invoice' => 'B1',
            'purchase_invoice' => 'B1',
            'delivery_receipt' => 'B6',
            'credit_memo' => 'B1',
            'debit_memo' => 'B1',
        ][$documentType] ?? 'B1';
    }

    private function normalizeTin(string $tin): string
    {
        return preg_replace('/\D+/', '', $tin) ?: '';
    }

    private function normalizedTinSql(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), '-', ''), ' ', ''), '.', ''), '/', '')";
    }

    private function toCentavos(mixed $pesos): int
    {
        return (int) round(((float) $pesos) * 100);
    }

    private function fromCentavos(mixed $centavos): float
    {
        return round(((float) $centavos) / 100, 2);
    }
}
