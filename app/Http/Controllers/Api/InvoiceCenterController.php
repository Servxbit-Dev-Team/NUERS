<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\ElectronicDocumentView;
use App\Services\InvoiceCalculator;
use App\Services\MerchantWallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InvoiceCenterController extends Controller
{
    private function actor(Request $request): object
    {
        $user = $request->bearerToken() ? app(ApiAccess::class)->user($request) : null;
        abort_unless($user, 401, 'Sign in to view electronic documents.');
        $user->role = DB::table('profiles')->where('id', $user->id)->value('role');
        abort_unless(in_array($user->role, ['admin', 'super_admin', 'bir', 'merchant'], true), 403, 'This document workspace requires business or administrative access.');

        return $user;
    }

    private function businesses(object $user)
    {
        $query = DB::table('merchants');
        if ($user->role === 'merchant') {
            $query->where(fn ($q) => $q->where('merchant_account_id', $user->id));
        }

        return $query;
    }

    private function ledger(object $user)
    {
        if (app(\App\Services\LedgerProjection::class)->ready()) {
            $indexed = app(\App\Services\LedgerProjection::class)->query(null, true, true, false)->whereIn('document_source', ['invoice','receipt']);
            if ($user->role === 'merchant') {
                $indexed->whereIn('merchant_id', $this->businesses($user)->select('id'));
            }
            $mapped = DB::query()->fromSub($indexed, 'd')->selectRaw("document_id as id,merchant_id,receipt_number as number,document_type,record_status,business,tin,created_at as issued_at,amount,document_source as source,sale_category,statutory_discount_amount,vat_relief_amount,discount_amount,manual_discount_amount,
                CASE WHEN record_status IN ('cancelled','canceled','void','voided','refunded','refund') THEN 'cancelled' WHEN record_status IN ('issued','verified','valid','completed','paid','settled','validated','partially_paid','sent') THEN 'valid' ELSE 'pending' END as status,
                CASE WHEN document_type IN ('credit_memo','debit_memo','credit_note','debit_note') THEN 'notes' WHEN document_type IN ('payment_receipt','official_receipt') THEN 'receipts' ELSE 'invoices' END as category");
            return DB::query()->fromSub($mapped, 'ledger');
        }
        $invoice = DB::table('business_invoices')->selectRaw("id, merchant_id, invoice_number as number, document_type, status as record_status, merchant_name as business, merchant_tin as tin, COALESCE(issue_date, created_at) as issued_at, total_amount as amount, 'invoice' as source, sale_category, statutory_discount_amount, vat_relief_amount, discount_amount, CASE WHEN discount_amount>statutory_discount_amount THEN discount_amount-statutory_discount_amount ELSE 0 END as manual_discount_amount");
        $receipt = DB::table('transaction_receipts')->selectRaw("id, merchant_id, receipt_number as number, COALESCE(document_type, receipt_type) as document_type, status as record_status, merchant_name as business, merchant_tin as tin, COALESCE(issued_at, created_at) as issued_at, total_due as amount, 'receipt' as source, sale_category, statutory_discount_amount, vat_relief_amount, discount_amount, CASE WHEN discount_amount>statutory_discount_amount THEN discount_amount-statutory_discount_amount ELSE 0 END as manual_discount_amount");
        if ($user->role === 'merchant') {
            $invoice->whereIn('merchant_id', $this->businesses($user)->select('id'));
            $receipt->whereIn('merchant_id', $this->businesses($user)->select('id'));
        }
        $union = DB::query()->fromSub($invoice->unionAll($receipt), 'documents');

        return DB::query()->fromSub($union->selectRaw("documents.*, CASE WHEN record_status IN ('cancelled','canceled','void','voided','refunded','refund') THEN 'cancelled' WHEN record_status IN ('issued','verified','valid','completed','paid','settled','validated','partially_paid','sent') THEN 'valid' ELSE 'pending' END as status, CASE WHEN document_type IN ('credit_memo','debit_memo','credit_note','debit_note') THEN 'notes' WHEN document_type IN ('payment_receipt','official_receipt') THEN 'receipts' ELSE 'invoices' END as category"), 'ledger');
    }

    private function filterValues(Request $request): array
    {
        return $request->validate([
            'sale_category' => ['nullable', Rule::in(['all', ...\App\Services\StatutorySales::CATEGORIES])],
            'search' => 'nullable|string|max:200', 'merchant_id' => 'nullable|string|max:80',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'status' => ['nullable', Rule::in(['all', 'valid', 'pending', 'cancelled'])],
            'type' => ['nullable', Rule::in(['all', 'invoices', 'receipts', 'notes', 'cancelled'])],
            'per_page' => 'nullable|integer|min:1|max:100', 'page' => 'nullable|integer|min:1',
        ]);
    }

    private function filteredLedger(object $user, array $v)
    {
        $query = $this->ledger($user);
        if (($v['sale_category'] ?? 'all') !== 'all') {
            $query->where('sale_category', $v['sale_category']);
        }
        if (! empty($v['merchant_id'])) {
            abort_unless($this->businesses($user)->where('id', $v['merchant_id'])->exists(), 404, 'Business not found.');
            $query->where('merchant_id', $v['merchant_id']);
        }
        if (! empty($v['search'])) {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v['search']).'%';
            $tinDigits = preg_match('/^[\d\s-]+$/', trim($v['search'])) ? preg_replace('/\D/', '', $v['search']) : '';
            $query->where(function ($q) use ($needle, $tinDigits) {
                $q->where('business', 'like', $needle)->orWhere('tin', 'like', $needle)->orWhere('number', 'like', $needle);
                if (strlen($tinDigits) >= 3) {
                    $q->orWhereRaw("REPLACE(REPLACE(COALESCE(tin, ''), '-', ''), ' ', '') LIKE ?", ['%'.$tinDigits.'%']);
                }
            });
        }
        if (! empty($v['from'])) {
            $query->where('issued_at', '>=', $v['from']);
        }
        if (! empty($v['to'])) {
            $query->where('issued_at', '<', \Carbon\CarbonImmutable::parse($v['to'])->addDay()->toDateString());
        }
        if (($v['status'] ?? 'all') !== 'all') {
            $query->where('status', $v['status']);
        }

        return $query;
    }

    private function documentType($query, array $v)
    {
        $type = $v['type'] ?? 'all';
        if ($type === 'cancelled') {
            $query->where('status', 'cancelled');
        } elseif ($type !== 'all') {
            $query->where('category', $type);
        }

        return $query;
    }

    public function index(Request $request)
    {
        $user = $this->actor($request);
        $v = $this->filterValues($request);
        $query = $this->filteredLedger($user, $v);
        $summary = (clone $query)->selectRaw("COUNT(*) as total, COALESCE(SUM(CASE WHEN category='invoices' THEN 1 ELSE 0 END),0) as invoices, COALESCE(SUM(CASE WHEN category='receipts' THEN 1 ELSE 0 END),0) as receipts, COALESCE(SUM(CASE WHEN category='notes' THEN 1 ELSE 0 END),0) as notes, COALESCE(SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END),0) as cancelled")->first();
        $this->documentType($query, $v);
        $page = $query->orderByDesc('issued_at')->orderByDesc('source')->orderByDesc('id')->paginate($v['per_page'] ?? 10);

        return response()->json(['summary' => $summary, 'data' => $page->items(), 'total' => $page->total(), 'last_page' => $page->lastPage(), 'current_page' => $page->currentPage(), 'can_create' => in_array($user->role, ['admin', 'super_admin', 'merchant'], true)]);
    }

    public function export(Request $request)
    {
        $user = $this->actor($request);
        $v = $this->filterValues($request);
        $query = $this->documentType($this->filteredLedger($user, $v), $v);
        $count = (clone $query)->count();
        if ($request->has('report_limit')) {
            \App\Services\ReportCsv::limit($request, $count);
        }

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $cell = function ($value) {
                $text = (string) ($value ?? '');

                return preg_match('/^[\s]*[=+@\-]|^[\t\r\n]/u', $text) ? "'".$text : $text;
            };
            fputcsv($out, ['Document No.', 'Date', 'Type', 'Business', 'TIN', 'Amount (PHP)', 'Status', 'Sale Category', 'Statutory Discount (PHP)', 'VAT Adjustment (PHP)', 'Manual Discount PHP', 'Total Discount PHP'], ',', '"', '');
            foreach ($query->orderByDesc('issued_at')->orderBy('source')->orderBy('id')->cursor() as $row) {
                fputcsv($out, array_map($cell, [$row->number, $row->issued_at, $row->document_type, $row->business, $row->tin, $row->amount, $row->record_status, $row->sale_category, $row->statutory_discount_amount, $row->vat_relief_amount, $row->manual_discount_amount, $row->discount_amount]), ',', '"', '');
            }
            fclose($out);
        }, 'NUERS-documents-'.($v['from'] ?? 'all').'-to-'.($v['to'] ?? 'latest').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Export-Count' => (string) $count,
        ]);
    }

    private function documentRecord(object $user, string $source, string $id): object
    {
        abort_unless(in_array($source, ['invoice', 'receipt'], true), 404);
        // Fetch one original by its primary key; preview must not scan the document ledger.
        $query = DB::table($source === 'invoice' ? 'business_invoices' : 'transaction_receipts')->where('id', $id);
        if ($user->role === 'merchant') {
            $query->whereIn('merchant_id', $this->businesses($user)->select('id'));
        }
        $row = $query->first();
        abort_unless($row, 404, 'Document not found.');
        return $row;
    }

    public function show(Request $request, string $source, string $id)
    {
        $row = $this->documentRecord($this->actor($request), $source, $id);

        return response()->json(['document' => app(ElectronicDocumentView::class)->make($source, $row)])->header('Cache-Control', 'private, no-store');
    }

    public function pdf(Request $request, string $source, string $id)
    {
        $user = $this->actor($request);
        abort_unless(in_array($user->role, ['admin', 'super_admin', 'bir'], true), 403);
        $row = $this->documentRecord($user, $source, $id);
        $document = app(ElectronicDocumentView::class)->make($source, $row);
        $renderer = app(\App\Services\DocumentPdf::class);
        $pdf = $renderer->render($document);
        app(BirCompliance::class)->audit('document.admin_copy.downloaded', $id, (string) $user->id, [
            'merchant_id' => $row->merchant_id, 'source' => $source, 'pdf_hash' => hash('sha256', $pdf),
        ]);
        return response($pdf)->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$renderer->filename($document).'"')
            ->header('Cache-Control', 'private, no-store');
    }

    public function merchants(Request $request)
    {
        $user = $this->actor($request);

        return response()->json(['businesses' => $this->businesses($user)->orderBy('business_name')->get(['id', 'business_name', 'tin', 'vat_registered'])]);
    }

    public function store(Request $request)
    {
        $user = $this->actor($request);
        abort_if($user->role === 'bir', 403);
        $v = $request->validate(['sales_benefit' => 'nullable|array', 'items.*.benefit_eligible' => 'sometimes|boolean', 'request_id' => 'required|uuid', 'merchant_id' => 'required|string', 'document_type' => ['required', Rule::in(['sales_invoice', 'official_receipt', 'credit_memo', 'debit_memo'])], 'original_invoice_id' => 'required_if:document_type,credit_memo,debit_memo|nullable|string|max:80', 'correction_reason' => 'required_if:document_type,credit_memo,debit_memo|nullable|string|min:10|max:2000', 'buyer_name' => 'required|string|max:255', 'buyer_tin' => 'nullable|string|max:80', 'buyer_address' => 'nullable|string|max:2000', 'issue_date' => 'required|date_format:Y-m-d', 'items' => 'required|array|min:1|max:100', 'items.*.description' => 'required|string|max:1000', 'items.*.quantity' => 'required|numeric|gt:0|max:1000000', 'items.*.unit_price' => 'required|numeric|min:0|max:100000000', 'vat_inclusive' => 'required|boolean', 'tax_type' => ['nullable', Rule::in(['vatable', 'non_vat', 'vat_exempt', 'zero_rated'])]]);
        $v['sales_benefit'] = app(\App\Services\StatutorySales::class)->validate($v['sales_benefit'] ?? null, $v['issue_date']);
        $merchant = $this->businesses($user)->where('id', $v['merchant_id'])->first();
        abort_unless($merchant, 404, 'Business not found.');
        if (in_array($v['document_type'], ['credit_memo', 'debit_memo'], true)) {
            abort_unless(DB::table('business_invoices')->where('id', $v['original_invoice_id'])->where('merchant_id', $merchant->id)->whereNotIn('status', ['draft', 'cancelled', 'voided'])->whereNotIn('document_type', ['credit_memo', 'debit_memo', 'official_receipt', 'payment_receipt'])->exists(), 422, 'Adjustment requires an original issued invoice of the same business.');
        }

        // The request UUID is also the document primary key, so network retries cannot create a second draft.
        return DB::transaction(function () use ($v, $merchant, $user) {
            DB::table('merchants')->where('id', $merchant->id)->lockForUpdate()->first();
            $existing = DB::table('business_invoices')->where('id', $v['request_id'])->first();
            if ($existing) {
                abort_unless($existing->merchant_id === $merchant->id && $existing->notes === 'draft-request:'.hash('sha256', json_encode($v)), 409, 'This request ID was already used for different document data.');

                return response()->json(['id' => $existing->id, 'source' => 'invoice', 'duplicate' => true]);
            }
            $calculator = app(InvoiceCalculator::class);
            $calculated = $calculator->calculate(array_map(fn ($item) => [
                ...$item, 'unit_price' => $calculator->cents($item['unit_price'], 'items.unit_price'),
                'tax_type' => $v['tax_type'] ?? ($merchant->vat_registered ? 'vatable' : 'non_vat'),
            ], $v['items']), (bool) $merchant->vat_registered, 0, 0, (bool) $v['vat_inclusive'], $v['sales_benefit']);
            $total = $calculated['total_amount'];
            app(MerchantWallet::class)->check((string) $merchant->id, (int) $total, $v['document_type']);
            $vatable = $calculated['taxable_amount'];
            $vat = $calculated['vat_amount'];
            $items = [];
            foreach ($calculated['items'] as $n => $item) {
                $items[] = [
                    'id' => (string) Str::uuid(), 'invoice_id' => $v['request_id'], 'merchant_id' => $merchant->id, 'line_number' => $n + 1,
                    'benefit_details' => isset($item['benefit_details']) ? json_encode($item['benefit_details']) : null, 'discount_amount' => $item['discount_amount'] / 100, 'description' => $item['description'], 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'] / 100,
                    'line_total' => $item['line_total'] / 100, 'taxable_amount' => $item['taxable_amount'] / 100, 'vat_amount' => $item['vat_amount'] / 100,
                    'vat_rate' => $item['vat_rate'], 'tax_type' => $item['tax_type'], 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            DB::table('business_invoices')->insert([...app(\App\Services\StatutorySales::class)->storage($v['sales_benefit'], $calculated, true), 'discount_amount' => $calculated['discount_amount'] / 100, 'id' => $v['request_id'], 'merchant_id' => $merchant->id, 'invoice_number' => 'DRAFT-'.strtoupper($v['request_id']), 'document_type' => $v['document_type'], 'status' => 'draft', 'merchant_name' => $merchant->business_name, 'merchant_tin' => $merchant->tin, 'merchant_address' => $merchant->address ?? null, 'original_invoice_id' => $v['original_invoice_id'] ?? null, 'correction_reason' => $v['correction_reason'] ?? null, 'buyer_name' => $v['buyer_name'], 'buyer_tin' => $v['buyer_tin'] ?? null, 'buyer_address' => $v['buyer_address'] ?? null, 'issue_date' => $v['issue_date'], 'subtotal_amount' => $calculated['subtotal_amount'] / 100, 'zero_rated_amount' => $calculated['zero_rated_amount'] / 100, 'vat_exempt_amount' => $calculated['vat_exempt_amount'] / 100, 'total_amount' => $total / 100, 'amount_due' => $total / 100, 'taxable_amount' => $vatable / 100, 'vat_amount' => $vat / 100, 'prices_include_vat' => (bool) $v['vat_inclusive'], 'vat_registered' => (bool) $merchant->vat_registered, 'vat_rate' => $vat > 0 ? 12 : 0, 'notes' => 'draft-request:'.hash('sha256', json_encode($v)), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('business_invoice_items')->insert($items);
            app(BirCompliance::class)->audit('invoice.draft.created', $v['request_id'], (string) $user->id, ['merchant_id' => $merchant->id]);

            return response()->json(['id' => $v['request_id'], 'source' => 'invoice'], 201);
        });
    }
}
