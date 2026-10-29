<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\EisDocumentReadiness;
use App\Services\ElectronicDocumentView;
use App\Services\InvoiceCalculator;
use App\Services\InvoiceSequence;
use App\Services\MerchantCustomers;
use App\Services\MerchantWallet;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        'issued',
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
            if ($request->query('type') === 'notes') {
                $query->whereIn('document_type', ['credit_memo', 'debit_memo', 'credit_note', 'debit_note']);
            } else {
                $query->where('document_type', (string) $request->query('type'));
            }
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

        $render = $request->validate(['render' => ['nullable', Rule::in(['pdf'])]]);
        if (($render['render'] ?? null) === 'pdf') {
            app(BirCompliance::class)->audit('merchant.document.render.authorized', (string) $record->id, (string) $request->attributes->get('api_actor')->id, ['merchant_id' => $merchant->id, 'actor_role' => $request->attributes->get('api_role'), 'format' => 'pdf', 'outcome' => 'authorized_for_source_render', 'correlation_id' => (string) Str::uuid()]);
        }

        return response()->json([
            'invoice' => $this->invoicePayload($record, true),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = $this->currentMerchant($request);

        abort_unless(Schema::hasTable('business_invoices'), 500, 'business_invoices table is not migrated.');

        $validated = $request->validate([
            'invoice_number' => ['required', 'string', 'max:50', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'document_type' => ['required', Rule::in(self::DOCUMENT_TYPES)],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'buyer_name' => ['required', 'string', 'min:2', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'request_id' => ['nullable', 'uuid'],
            'customer_id' => ['nullable', 'uuid'],
            'customer_revision' => ['nullable', 'integer', 'min:1'],
            'buyer_customer_type' => ['nullable', Rule::in(['business', 'retail', 'walk_in'])],
            'buyer_phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ()-]{7,30}$/'],
            'buyer_tin' => ['nullable', 'string', 'regex:/^(?:[0-9]{3}[ -]?){2}[0-9]{3}(?:[ -]?(?:[0-9]{3}|[0-9]{5}))?$/'],
            'buyer_address' => ['nullable', 'string', 'max:1000'],
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
            'correction_reason' => ['nullable', 'string', 'max:2000'],
            'withholding_details' => ['nullable', 'array'],
            'manual_recovery_id' => ['nullable', 'uuid'],
            'qr_payload' => ['nullable', 'string', 'max:8000'],
            'digital_signature' => ['nullable', 'string'],
            'validation_hash' => ['nullable', 'string', 'max:255'],
            'formats_generated' => ['nullable', 'array'],
            'formats_generated.*' => ['string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms_and_conditions' => ['nullable', 'string', 'max:5000'],
            'footer_note' => ['nullable', 'string', 'max:2000'],
            'sales_benefit' => ['nullable', 'array'],
            'line_items.*.benefit_eligible' => ['sometimes', 'boolean'],
            'line_items' => ['required', 'array', 'min:1', 'max:100'],
            'line_items.*.line_number' => ['required', 'integer', 'min:1'],
            'line_items.*.item_code' => ['nullable', 'string', 'max:80'],
            'line_items.*.description' => ['required', 'string', 'max:1000'],
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

        $validated['sales_benefit'] = app(\App\Services\StatutorySales::class)->validate($validated['sales_benefit'] ?? null, $validated['issue_date']);
        $request->merge(['sales_benefit' => $validated['sales_benefit']]);
        $request->validate(['prices_include_vat'=>'sometimes|boolean']);
        $totals = $this->totalsFromRequest($request, (bool) $merchant->vat_registered);
        $validated['line_items'] = $totals['_items'];
        $validated['withholding_details'] = $request->input('withholding_details');
        $documentType = $this->normalizeDocumentType($validated['document_type'], $validated['line_items'], $totals, (bool) ($merchant->vat_registered ?? false));
        abort_if($documentType === 'debit_memo', 422, 'Use a new electronic invoice referencing the original for an increase (RMC 98-2026).');
        if ($documentType === 'credit_memo' || !empty($validated['original_invoice_id'])) {
            $original = DB::table('business_invoices')->where('merchant_id', $merchant->id)->whereNotIn('status', ['draft', 'cancelled', 'voided'])->whereNotIn('document_type', ['credit_memo', 'debit_memo', 'credit_note', 'debit_note', 'payment_receipt', 'official_receipt', 'purchase_invoice', 'delivery_receipt'])->where(fn ($q) => $q->where('id', $validated['original_invoice_id'] ?? '')->orWhere('invoice_number', $validated['original_invoice_id'] ?? ''))->first();
            abort_unless($original && trim((string) ($validated['correction_reason'] ?? '')), 422, 'An adjustment requires the original issued invoice of this seller and a correction reason.');
            $validated['original_invoice_id'] = $original->id;
            $validated['reference_number'] = $original->invoice_number;
        }
        $templateCode = $this->templateCode($documentType);
        $now = now();
        $invoiceId = (string) Str::uuid();
        $status = ($validated['status'] ?? 'draft') === 'sent' ? 'issued' : ($validated['status'] ?? 'draft');
        abort_unless(in_array($status, ['draft', 'issued'], true), 422, 'New documents must be draft or issued. Payment and cancellation require their separate evidence-backed workflows.');
        if ($status !== 'draft') {
            abort_if(Carbon::parse($validated['issue_date'])->isAfter(today()), 422, 'An issued invoice cannot have a future business date.');
        }
        $authority = ['permit_to_issue_number' => null, 'esp_accreditation_number' => null];

        $requestHash = hash('sha256', json_encode([$validated, $totals], JSON_THROW_ON_ERROR));
        $customerResult = DB::transaction(function () use ($request, $validated, $merchant, $invoiceId, $status, $totals, $documentType, $templateCode, $now, $authority, $requestHash) {
            $merchant = DB::table('merchants')->where('id', $merchant->id)->lockForUpdate()->first();
            app(\App\Services\MerchantTeamAccess::class)->recheckMutation($request);
            if (! empty($validated['request_id'])) {
                $previous = DB::table('invoice_create_requests')->where('merchant_id', $merchant->id)->where('request_id', $validated['request_id'])->first();
                if ($previous) {
                    abort_unless(hash_equals($previous->request_hash, $requestHash), 409, 'This save identifier already belongs to another invoice request. Open the saved document before creating another.');
                    $savedCustomer = DB::table('merchant_customers')->where('merchant_id', $merchant->id)->where('id', $previous->customer_id)->first();
                    abort_unless($savedCustomer, 409, 'The saved customer relationship requires review.');

                    return ['customer' => app(MerchantCustomers::class)->row($savedCustomer), 'created' => (bool) $previous->customer_created, 'invoice_id' => $previous->invoice_id, 'replayed' => true];
                }
            }

            app(\App\Services\MerchantInvoiceSettings::class)->assertPolicy($merchant,$validated['line_items'],$validated['buyer_tin']??null);
            abort_if(DB::table('business_invoices')->where('merchant_id', $merchant->id)->where('invoice_number', $validated['invoice_number'])->exists(), 422, 'This invoice number already exists for your Business Account.');
            if (! empty($validated['buyer_phone'])) {
                abort_unless(strlen(preg_replace('/\D/', '', $validated['buyer_phone'])) >= 7 && strlen(preg_replace('/\D/', '', $validated['buyer_phone'])) <= 15, 422, 'Buyer phone must contain between seven and fifteen digits.');
            }
            $wallet = app(MerchantWallet::class);
            $wallet->check((string) $merchant->id, app(InvoiceCalculator::class)->cents($totals['total_amount'], 'total_amount'), $documentType);
            $customerResult = app(MerchantCustomers::class)->resolveInvoiceBuyer((string) $merchant->id, (string) $request->attributes->get('api_actor')->id, $validated);
            $validated['customer_id'] = $customerResult['customer']['id'];
            if ($status !== 'draft') {
                app(\App\Services\InvoiceDocumentRules::class)->assertBuyer($validated['buyer_customer_type'] ?? ($customerResult['customer']['type'] ?? null), $validated['buyer_name'], $validated['buyer_tin'] ?? null, $validated['buyer_address'] ?? null);
                $authority = app(BirCompliance::class)->assertCanIssue((string) $merchant->id);
                $integration = app(BirCompliance::class)->integration((string) $merchant->id, null, 'production');
                $allocated = app(InvoiceSequence::class)->reserve((string) $merchant->id, (string) ($integration->branch_code ?? ''), in_array($documentType, ['credit_memo', 'debit_memo'], true) ? $documentType : (in_array($documentType, ['payment_receipt', 'official_receipt'], true) ? 'payment' : 'invoice'));
                $validated['invoice_number'] = $allocated['number'];
                $wallet->charge((string) $merchant->id, app(InvoiceCalculator::class)->cents($totals['total_amount'], 'total_amount'), $documentType, 'invoice', $invoiceId, $allocated['number'], (string) ($integration->branch_code ?? ''), 'portal', (string) $request->attributes->get('api_actor')->id);
            }
            $invoiceRow = [
                ...$authority,
                'id' => $invoiceId,
                'merchant_id' => $merchant->id,
                'invoice_number' => $validated['invoice_number'],
                'document_registration' => isset($allocated) ? json_encode($allocated['document_registration'], JSON_THROW_ON_ERROR) : null,
                'document_type' => $documentType,
                'status' => $status,
                'version_number' => 1,
                'parent_invoice_id' => null,
                'merchant_name' => $merchant->business_name,
                'merchant_tin' => $merchant->tin,
                'merchant_address' => $this->merchantAddress($merchant),
                'buyer_name' => $validated['buyer_name'],
                'buyer_customer_type' => $validated['buyer_customer_type'] ?? ($customerResult['customer']['type'] ?? null),
                'buyer_tin' => $request->input('buyer_tin'),
                'buyer_address' => $request->input('buyer_address'),
                'buyer_email' => $request->input('buyer_email'),
                'buyer_phone' => $validated['buyer_phone'] ?? null,
                'issue_date' => $validated['issue_date'],
                'due_date' => $request->input('due_date') ?: null,
                'delivery_date' => $request->input('delivery_date') ?: null,
                'period_start' => $request->input('period_start') ?: null,
                'period_end' => $request->input('period_end') ?: null,
                'subtotal_amount' => $totals['subtotal_amount'],
                ...app(\App\Services\StatutorySales::class)->storage($validated['sales_benefit'], $totals),
                'discount_amount' => $totals['discount_amount'],
                'taxable_amount' => $totals['taxable_amount'],
                'vat_amount' => $totals['vat_amount'],
                'withholding_tax' => $totals['withholding_tax'],
                'manual_recovery_id' => $validated['manual_recovery_id'] ?? null,
                'withholding_details' => $request->filled('withholding_details') ? json_encode($request->input('withholding_details'), JSON_THROW_ON_ERROR) : null,
                'other_charges' => $totals['other_charges'],
                'total_amount' => $totals['total_amount'],
                'amount_paid' => $totals['amount_paid'],
                'amount_due' => $totals['amount_due'],
                'currency' => 'PHP',
                'vat_registered' => (bool) ($merchant->vat_registered ?? false),
                'vat_rate' => 12,
                'zero_rated_amount' => $totals['zero_rated_amount'],
                'vat_exempt_amount' => $totals['vat_exempt_amount'],
                'reference_number' => $validated['reference_number'] ?? null,
                'purchase_order' => $request->input('purchase_order'),
                'sales_order' => $request->input('sales_order'),
                'original_invoice_id' => $validated['original_invoice_id'] ?? null,
                'correction_reason' => $request->input('correction_reason'),
                'qr_payload' => $request->input('qr_payload'),
                'digital_signature' => null,
                'signed_at' => null,
                'validated_at' => $now,
                'validation_hash' => hash('sha256', json_encode([$validated['line_items'], array_diff_key($totals, ['_items' => true])], JSON_THROW_ON_ERROR)),
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

            if (Schema::hasColumn('business_invoices', 'seller_branch_code')) {
                $invoiceRow['seller_branch_code'] = $status === 'draft' ? null : app(BirCompliance::class)->integration((string) $merchant->id, null, 'production')?->branch_code;
                $invoiceRow['seller_rdo_code'] = $merchant->rdo_code ?? null;
            }
            $invoiceRow['presentation_settings']=json_encode(app(\App\Services\MerchantInvoiceSettings::class)->snapshot($merchant),JSON_THROW_ON_ERROR);
            if (!empty($validated['manual_recovery_id'])) {
                abort_unless(DB::table('merchant_compliance_records')->where('merchant_id',$merchant->id)->where('id',$validated['manual_recovery_id'])->where('kind','manual_invoice')->where('status','awaiting_replacement')->exists(),422,'Select an unreconciled manual invoice from this business.');
            }
            DB::table('business_invoices')->insert($invoiceRow);
            if (! empty($validated['customer_id'])) {
                app(MerchantCustomers::class)->link($merchant->id, $validated['customer_id'], (string) $request->attributes->get('api_actor')->id, 'invoice', $invoiceId);
                app(BirCompliance::class)->audit('customer.document_linked', $validated['customer_id'], (string) $request->attributes->get('api_actor')->id, ['merchant_id' => $merchant->id, 'source' => 'invoice', 'document_id' => $invoiceId]);
            }

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
                    'benefit_details' => isset($line['benefit_details']) ? json_encode($line['benefit_details']) : null,
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

            if ($status !== 'draft' && !empty($validated['manual_recovery_id'])) app(\App\Services\ComplianceOperations::class)->linkManual((string)$merchant->id,$validated['manual_recovery_id'],'invoice',$invoiceId,(string)$request->attributes->get('api_actor')->id);
            if ($status !== 'draft' && Schema::hasTable('eis_document_outbox')) {
                app(EisDocumentReadiness::class)->prepare('invoice', $invoiceId);
            }
            $validated['document_type'] = $documentType;
            if ($status !== 'draft') {
                $this->mirrorBuyerExpense($merchant, $invoiceId, $validated, $totals, $now);
            }
            app(BirCompliance::class)->audit($status === 'draft' ? 'invoice.draft.saved' : 'invoice.issued', $invoiceId, (string) app(ApiAccess::class)->user($request)?->id, ['status' => $status, 'authority' => $authority, 'document_hash' => hash('sha256', json_encode([$invoiceRow, $validated['line_items']], JSON_THROW_ON_ERROR))]);
            if (! empty($validated['request_id'])) {
                DB::table('invoice_create_requests')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant->id, 'request_id' => $validated['request_id'], 'request_hash' => $requestHash, 'invoice_id' => $invoiceId, 'customer_id' => $customerResult['customer']['id'], 'customer_created' => $customerResult['created'], 'created_at' => $now, 'updated_at' => $now]);
            }

            return [...$customerResult, 'invoice_id' => $invoiceId, 'replayed' => false];
        }, 3);

        $invoice = DB::table('business_invoices')->where('merchant_id', $merchant->id)->where('id', $customerResult['invoice_id'])->first();

        return response()->json([
            'request_id' => $validated['request_id'] ?? null,
            'invoice' => $this->invoicePayload($invoice, true),
            'customer' => ['id' => $customerResult['customer']['id'], 'code' => $customerResult['customer']['code'], 'created' => $customerResult['created']],
        ], $customerResult['replayed'] ? 200 : 201);
    }

    private function currentMerchant(Request $request): object
    {
        return app(ApiAccess::class)->merchant($request);
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
            'buyer_phone' => $invoice->buyer_phone ?? null,
            'issue_date' => $invoice->issue_date,
            'due_date' => $invoice->due_date,
            'delivery_date' => $invoice->delivery_date,
            'period_start' => $invoice->period_start,
            'period_end' => $invoice->period_end,
            'subtotal_amount' => $this->toCentavos($invoice->subtotal_amount),
            'sales_benefit' => json_decode($invoice->sales_benefit ?? 'null', true), 'sale_category' => $invoice->sale_category ?? 'regular',
            'statutory_discount_amount' => $this->toCentavos($invoice->statutory_discount_amount ?? 0), 'vat_relief_amount' => $this->toCentavos($invoice->vat_relief_amount ?? 0),
            'discount_amount' => $this->toCentavos($invoice->discount_amount),
            'taxable_amount' => $this->toCentavos($invoice->taxable_amount),
            'vat_amount' => $this->toCentavos($invoice->vat_amount),
            'withholding_tax' => $this->toCentavos($invoice->withholding_tax),
            'withholding_details' => json_decode($invoice->withholding_details ?? 'null', true),
            'manual_recovery_id' => $invoice->manual_recovery_id ?? null,
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
            $payload['document_view'] = app(ElectronicDocumentView::class)->make('invoice', $invoice);
            $payload['line_items'] = DB::table('business_invoice_items')
                ->where('invoice_id', $invoice->id)
                ->where('merchant_id', $invoice->merchant_id)
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
                    'benefit_details' => json_decode($line->benefit_details ?? 'null', true), 'benefit_eligible' => !empty($line->benefit_details),
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

    private function totalsFromRequest(Request $request, bool $vatRegistered = true): array
    {
        $calculator = app(InvoiceCalculator::class);
        $computed = $calculator->calculate($request->input('line_items', []), $vatRegistered,
            0,
            $calculator->cents($request->input('other_charges', 0), 'other_charges', true), $request->boolean('prices_include_vat', true), $request->input('sales_benefit'));
        $rate = $request->input('withholding_pct', 0);
        if (! is_numeric($rate) || $rate < 0 || $rate > 100 || abs($rate * 100 - round($rate * 100)) > 0.00001) {
            throw ValidationException::withMessages(['withholding_pct' => 'Use a percentage from 0 to 100 with at most two decimals.']);
        }
        $computed['withholding_tax'] = intdiv(($computed['taxable_amount'] + $computed['zero_rated_amount'] + $computed['vat_exempt_amount']) * (int) round($rate * 100) + 5000, 10000);
        if ($request->filled('withholding_details')) {
            $details = app(\App\Services\WithholdingEvidence::class)->validate($request->input('withholding_details'), $computed['total_amount']);
            $computed['withholding_tax'] = $details['amount_centavos'];
            $request->merge(['withholding_details' => $details]);
        }
        $paid = $calculator->cents($request->input('amount_paid', 0), 'amount_paid', true);
        abort_if($paid > $computed['total_amount'] - $computed['withholding_tax'], 422, 'Amount paid exceeds the net amount due.');
        $computed['amount_paid'] = $paid;
        $computed['amount_due'] = $computed['total_amount'] - $paid - $computed['withholding_tax'];
        $items = $computed['items'];
        unset($computed['items']);
        foreach ($computed as $key => $value) {
            if ($request->has($key) && $calculator->cents($request->input($key), $key, true) !== $value) {
                throw ValidationException::withMessages([$key => 'Submitted amount does not match the server calculation.']);
            }
        }

        return [...array_map(fn ($value) => $value / 100, $computed), '_items' => $items];
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

        $buyers = DB::table('merchants')->whereRaw($this->normalizedTinSql('tin').' = ?', [$buyerTin])->limit(2)->get();
        $buyer = $buyers->count() === 1 ? $buyers->first() : null;

        if (! $buyer) {
            return;
        }

        if ($buyer->id === $seller->id || ! in_array($validated['document_type'], ['vat_invoice', 'non_vat_invoice', 'vat_exempt_invoice', 'zero_rated_invoice', 'mixed_sales_invoice', 'sales_invoice', 'service_invoice'], true)) {
            return;
        }
        DB::table('merchant_expenses')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'merchant_id' => $buyer->id, 'supplier_merchant_id' => $seller->id, 'source_invoice_id' => $invoiceId,
            'source_invoice_reference' => $validated['invoice_number'], 'buyer_tin' => $buyer->tin, 'buyer_name' => $validated['buyer_name'],
            'supplier_name' => $seller->business_name, 'supplier_tin' => $seller->tin, 'purchase_order_reference' => $validated['purchase_order'] ?? null,
            'expense_category' => 'B2B Purchase', 'description' => 'Pending review of original supplier invoice '.$validated['invoice_number'],
            'gross_amount' => $totals['total_amount'], 'vatable_amount' => $totals['taxable_amount'], 'input_vat_amount' => $totals['vat_amount'],
            'withholding_tax_amount' => $totals['withholding_tax'], 'net_payable' => max(0, $totals['amount_due']), 'document_type' => $validated['document_type'],
            'payment_status' => 'unpaid', 'validation_status' => 'pending_review', 'reconciliation_status' => 'unmatched', 'claim_status' => 'pending_review',
            'risk_level' => null, 'ai_score' => null, 'issued_at' => $validated['issue_date'], 'due_at' => $validated['due_date'] ?? null,
            'metadata' => json_encode(['source' => 'business_invoices', 'source_invoice_id' => $invoiceId, 'seller_merchant_id' => $seller->id]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
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
        $address = trim((string) (($merchant->business_registration_address ?? '') ?: $merchant->address));

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

        if (! in_array($documentType, ['sales_invoice', 'vat_invoice', 'non_vat_invoice', 'vat_exempt_invoice', 'zero_rated_invoice', 'mixed_sales_invoice'], true)) {
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
            $taxType === 'non_vat' || ! $vatRegistered => 'non_vat_invoice',
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
