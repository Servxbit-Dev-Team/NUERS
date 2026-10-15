<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InvoiceCenterController extends Controller
{
    private function actor(Request $request): object
    {
        $user = $request->bearerToken() ? DB::table('users')->where('api_token', $request->bearerToken())->first() : null;
        abort_unless($user, 401, 'Sign in to view electronic documents.');
        $user->role = DB::table('profiles')->where('id', $user->id)->value('role');
        abort_unless(in_array($user->role, ['admin', 'super_admin', 'bir', 'merchant'], true), 403, 'This document workspace requires business or administrative access.');
        return $user;
    }

    private function businesses(object $user)
    {
        $query = DB::table('merchants');
        if ($user->role === 'merchant') $query->where(fn ($q) => $q->where('merchant_account_id', $user->id)->orWhere('merchant_account_email', $user->email)->orWhere('email', $user->email));
        return $query;
    }

    private function ledger(object $user)
    {
        $invoice = DB::table('business_invoices')->selectRaw("id, merchant_id, invoice_number as number, document_type, status as record_status, merchant_name as business, merchant_tin as tin, COALESCE(issue_date, created_at) as issued_at, total_amount as amount, 'invoice' as source");
        $receipt = DB::table('transaction_receipts')->selectRaw("id, merchant_id, receipt_number as number, COALESCE(document_type, receipt_type) as document_type, status as record_status, merchant_name as business, merchant_tin as tin, COALESCE(issued_at, created_at) as issued_at, total_due as amount, 'receipt' as source");
        if ($user->role === 'merchant') {
            $invoice->whereIn('merchant_id', $this->businesses($user)->select('id'));
            $receipt->whereIn('merchant_id', $this->businesses($user)->select('id'));
        }
        $union = DB::query()->fromSub($invoice->unionAll($receipt), 'documents');
        return DB::query()->fromSub($union->selectRaw("documents.*, CASE WHEN record_status IN ('cancelled','canceled','void','voided','refunded','refund') THEN 'cancelled' WHEN record_status IN ('issued','verified','valid','completed','paid','settled','validated','partially_paid','sent') THEN 'valid' ELSE 'pending' END as status, CASE WHEN document_type IN ('credit_memo','debit_memo','credit_note','debit_note') THEN 'notes' WHEN document_type IN ('payment_receipt','official_receipt') THEN 'receipts' ELSE 'invoices' END as category"), 'ledger');
    }

    public function index(Request $request)
    {
        $user = $this->actor($request);
        $v = $request->validate(['search' => 'nullable|string|max:200', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'status' => ['nullable', Rule::in(['all','valid','pending','cancelled'])], 'type' => ['nullable', Rule::in(['all','invoices','receipts','notes','cancelled'])], 'per_page' => 'nullable|integer|min:1|max:100', 'page' => 'nullable|integer|min:1']);
        $query = $this->ledger($user);
        if (!empty($v['search'])) {
            $needle = '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $v['search']).'%';
            $query->where(fn ($q) => $q->where('business', 'like', $needle)->orWhere('tin', 'like', $needle)->orWhere('number', 'like', $needle));
        }
        if (!empty($v['from'])) $query->whereDate('issued_at', '>=', $v['from']);
        if (!empty($v['to'])) $query->whereDate('issued_at', '<=', $v['to']);
        if (($v['status'] ?? 'all') !== 'all') $query->where('status', $v['status']);
        $summary = (clone $query)->selectRaw("COUNT(*) as total, COALESCE(SUM(CASE WHEN category='invoices' THEN 1 ELSE 0 END),0) as invoices, COALESCE(SUM(CASE WHEN category='receipts' THEN 1 ELSE 0 END),0) as receipts, COALESCE(SUM(CASE WHEN category='notes' THEN 1 ELSE 0 END),0) as notes, COALESCE(SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END),0) as cancelled")->first();
        $type = $v['type'] ?? 'all';
        if ($type === 'cancelled') $query->where('status', 'cancelled');
        elseif ($type !== 'all') $query->where('category', $type);
        $page = $query->orderByDesc('issued_at')->orderBy('source')->orderBy('id')->paginate($v['per_page'] ?? 10);
        return response()->json(['summary' => $summary, 'data' => $page->items(), 'total' => $page->total(), 'last_page' => $page->lastPage(), 'current_page' => $page->currentPage(), 'can_create' => in_array($user->role, ['admin','super_admin','merchant'], true)]);
    }

    public function show(Request $request, string $source, string $id)
    {
        $user = $this->actor($request);
        abort_unless(in_array($source, ['invoice', 'receipt'], true), 404);
        $record = $this->ledger($user)->where('source', $source)->where('id', $id)->first();
        abort_unless($record, 404, 'Document not found.');
        $row = DB::table($source === 'invoice' ? 'business_invoices' : 'transaction_receipts')->where('id', $id)->first();
        $items = $source === 'invoice' ? DB::table('business_invoice_items')->where('invoice_id', $id)->orderBy('line_number')->get()->map(fn ($i) => ['description' => $i->description, 'quantity' => (float)$i->quantity, 'unit_price' => (float)$i->unit_price, 'amount' => (float)$i->line_total])->all() : (json_decode($row->items ?? '[]', true) ?: []);
        $transaction = $source === 'receipt' && $row->transaction_id ? DB::table('merchant_transactions')->where('id', $row->transaction_id)->first() : null;
        return response()->json(['document' => array_merge((array)$record, [
            'seller_address' => $row->merchant_address, 'buyer_name' => $row->buyer_name ?: 'Walk-in customer', 'buyer_tin' => $row->buyer_tin, 'buyer_address' => $row->buyer_address ?? null,
            'reference' => $transaction->transaction_ref ?? $row->reference_number ?? $row->transaction_id ?? $row->id,
            'payment_method' => $transaction->payment_method ?? null, 'branch' => $transaction->branch ?? null,
            'vat_registered' => $row->vat_registered ?? ($row->merchant_vat_reg ?? null),
            'items' => array_map(fn ($i) => ['description' => $i['description'] ?? $i['desc'] ?? $i['name'] ?? 'Item', 'quantity' => (float)($i['quantity'] ?? $i['qty'] ?? 1), 'unit_price' => (float)($i['unit_price'] ?? $i['unitPrice'] ?? $i['price'] ?? 0), 'amount' => (float)($i['amount'] ?? $i['line_total'] ?? $i['total'] ?? (($i['unit_price'] ?? $i['unitPrice'] ?? $i['price'] ?? 0) * ($i['quantity'] ?? $i['qty'] ?? 1)))], $items),
            'subtotal' => (float)($row->subtotal_amount ?? $row->gross_amount ?? 0), 'discount' => (float)$row->discount_amount,
            'vatable' => (float)($row->taxable_amount ?? $row->vatable_sales ?? 0), 'vat' => (float)$row->vat_amount,
            'withholding' => (float)($row->withholding_tax ?? 0), 'other_charges' => (float)($row->other_charges ?? 0),
            'permit_number' => $row->permit_to_issue_number ?? null, 'accreditation_number' => $row->esp_accreditation_number ?? null,
            'verification_url' => $source === 'receipt' ? url('/verify').'?receipt='.rawurlencode($row->receipt_number) : null,
            'authority_status' => 'BIR acceptance not verified',
        ])]);
    }

    public function merchants(Request $request)
    {
        $user = $this->actor($request);
        abort_if($user->role === 'bir', 403);
        return response()->json(['businesses' => $this->businesses($user)->orderBy('business_name')->get(['id','business_name','tin'])]);
    }

    public function store(Request $request)
    {
        $user = $this->actor($request);
        abort_if($user->role === 'bir', 403);
        $v = $request->validate(['request_id' => 'required|uuid', 'merchant_id' => 'required|string', 'document_type' => ['required', Rule::in(['sales_invoice','official_receipt','credit_memo','debit_memo'])], 'buyer_name' => 'required|string|max:255', 'buyer_tin' => 'nullable|string|max:80', 'buyer_address' => 'nullable|string|max:2000', 'issue_date' => 'required|date_format:Y-m-d', 'items' => 'required|array|min:1|max:100', 'items.*.description' => 'required|string|max:1000', 'items.*.quantity' => 'required|numeric|gt:0|max:1000000', 'items.*.unit_price' => 'required|numeric|min:0|max:100000000', 'vat_inclusive' => 'required|boolean']);
        $merchant = $this->businesses($user)->where('id', $v['merchant_id'])->first();
        abort_unless($merchant, 404, 'Business not found.');
        // The request UUID is also the document primary key, so network retries cannot create a second draft.
        return DB::transaction(function () use ($v, $merchant, $user) {
            DB::table('merchants')->where('id', $merchant->id)->lockForUpdate()->first();
            $existing = DB::table('business_invoices')->where('id', $v['request_id'])->first();
            if ($existing) {
                abort_unless($existing->merchant_id === $merchant->id && $existing->notes === 'draft-request:'.hash('sha256', json_encode($v)), 409, 'This request ID was already used for different document data.');
                return response()->json(['id' => $existing->id, 'source' => 'invoice', 'duplicate' => true]);
            }
            $total = 0; $vatable = 0; $vat = 0; $items = [];
            foreach ($v['items'] as $n => $item) {
                $price = (int)round($item['unit_price'] * 100); $amount = (int)round($price * $item['quantity']); $total += $amount;
                $net = $v['vat_inclusive'] ? (int)round($amount / 1.12) : 0; $lineVat = $v['vat_inclusive'] ? $amount - $net : 0; $vatable += $net; $vat += $lineVat;
                $items[] = ['id' => (string)Str::uuid(), 'invoice_id' => $v['request_id'], 'merchant_id' => $merchant->id, 'line_number' => $n+1, 'description' => $item['description'], 'quantity' => $item['quantity'], 'unit_price' => $price/100, 'line_total' => $amount/100, 'taxable_amount' => $net/100, 'vat_amount' => $lineVat/100, 'vat_rate' => $v['vat_inclusive'] ? 12 : 0, 'tax_type' => $v['vat_inclusive'] ? 'vatable' : 'non_vat', 'created_at' => now(), 'updated_at' => now()];
            }
            abort_if($total > 999999999999999, 422, 'Document total exceeds the supported amount.');
            abort_if($v['vat_inclusive'] && !$merchant->vat_registered, 422, 'VAT-inclusive amounts require a VAT-registered business.');
            DB::table('business_invoices')->insert(['id' => $v['request_id'], 'merchant_id' => $merchant->id, 'invoice_number' => 'DRAFT-'.strtoupper($v['request_id']), 'document_type' => $v['document_type'], 'status' => 'draft', 'merchant_name' => $merchant->business_name, 'merchant_tin' => $merchant->tin, 'merchant_address' => $merchant->address ?? null, 'buyer_name' => $v['buyer_name'], 'buyer_tin' => $v['buyer_tin'] ?? null, 'buyer_address' => $v['buyer_address'] ?? null, 'issue_date' => $v['issue_date'], 'subtotal_amount' => $total/100, 'total_amount' => $total/100, 'amount_due' => $total/100, 'taxable_amount' => $vatable/100, 'vat_amount' => $vat/100, 'vat_registered' => (bool)$merchant->vat_registered, 'vat_rate' => $v['vat_inclusive'] ? 12 : 0, 'notes' => 'draft-request:'.hash('sha256', json_encode($v)), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('business_invoice_items')->insert($items);
            app(\App\Services\BirCompliance::class)->audit('invoice.draft.created', $v['request_id'], (string)$user->id, ['merchant_id' => $merchant->id]);
            return response()->json(['id' => $v['request_id'], 'source' => 'invoice'], 201);
        });
    }
}
