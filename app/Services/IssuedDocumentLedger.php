<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Canonical document population. Payment rows are linked evidence, never separate invoice sales. */
class IssuedDocumentLedger
{
    private function col(string $table, string $alias, string $column, string $fallback = 'NULL'): string
    {
        return Schema::hasColumn($table, $column) ? $alias.'.'.$column : $fallback;
    }

    private function prefix(string $prefix, string $column): string
    {
        return DB::getDriverName() === 'sqlite' ? "'$prefix' || $column" : "CONCAT('$prefix',$column)";
    }

    public function candidates(): Builder
    {
        $rbranch = $this->col('transaction_receipts', 'r', 'seller_branch_code');
        $bbranch = $this->col('business_invoices', 'b', 'seller_branch_code');
        if (Schema::hasTable('eis_document_branch_reviews')) {
            $rbranch = "COALESCE($rbranch,(SELECT br.branch_code FROM eis_document_branch_reviews br WHERE br.document_source='receipt' AND br.document_id=r.id ORDER BY br.revision DESC LIMIT 1))";
            $bbranch = "COALESCE($bbranch,(SELECT br.branch_code FROM eis_document_branch_reviews br WHERE br.document_source='invoice' AND br.document_id=b.id ORDER BY br.revision DESC LIMIT 1))";
        }
        $benefitCols = fn ($table, $alias) => implode(',', array_map(fn ($c) => $this->col($table, $alias, $c, $c === 'sale_category' ? "'regular'" : '0').' as '.$c, ['sale_category', 'statutory_discount_amount', 'vat_relief_amount', 'discount_amount']));
        $rb = $benefitCols('transaction_receipts', 'r'); $bb = $benefitCols('business_invoices', 'b');
        $sspt = $this->col('transaction_receipts', 'r', 'sspt_sales', '0');
        $brdo = $this->col('business_invoices', 'b', 'seller_rdo_code');
        // At most one representative payment per receipt. All payment events remain in merchant_transactions.
        $receipts = DB::table('transaction_receipts as r')->leftJoin('merchant_transactions as t', function ($j) {
            $j->on('t.id', '=', DB::raw('(SELECT tx.id FROM merchant_transactions tx WHERE tx.receipt_id=r.id AND tx.merchant_id=r.merchant_id ORDER BY tx.created_at,tx.id LIMIT 1)'));
        })->selectRaw('COALESCE(t.id,'.$this->prefix('receipt:', 'r.id').") as id, 'receipt' as document_source,r.id as document_id,t.id as transaction_id,r.merchant_id,t.transaction_ref,r.id as receipt_id,r.receipt_number as receipt_number,
            r.merchant_name as business,r.merchant_tin as tin,r.merchant_address as seller_address,$rbranch as branch_code,r.rdo_code,
            COALESCE(r.document_type,r.receipt_type) as document_type,r.status as record_status,t.status as transaction_status,
            r.total_due as amount,$rb,r.vat_amount,r.vatable_sales,r.vat_exempt_sales,r.zero_rated_sales,$sspt as sspt_sales,
            (r.vatable_sales+r.vat_exempt_sales+r.zero_rated_sales+$sspt) as net_amount,
            r.issued_at as business_date,COALESCE(r.issued_at,r.created_at,t.created_at) as created_at,r.created_at as recorded_at,
            t.payment_method,t.region,t.branch,t.channel,r.buyer_name as customer_name,r.buyer_tin as customer_tin,
            NULL as original_invoice_id,
            (SELECT COUNT(*) FROM merchant_transactions tx WHERE tx.receipt_id=r.id AND tx.merchant_id=r.merchant_id) as payment_count,
            COALESCE((SELECT SUM(tx.amount) FROM merchant_transactions tx WHERE tx.receipt_id=r.id AND tx.merchant_id=r.merchant_id AND tx.status IN ('completed','paid','settled','verified')),0) as payment_amount");
        $invoices = DB::table('business_invoices as b')->whereNotIn('b.status', ['draft'])->selectRaw($this->prefix('invoice:', 'b.id')." as id,'invoice' as document_source,b.id as document_id,NULL as transaction_id,b.merchant_id,b.invoice_number as transaction_ref,NULL as receipt_id,b.invoice_number as receipt_number,
            b.merchant_name as business,b.merchant_tin as tin,b.merchant_address as seller_address,$bbranch as branch_code,$brdo as rdo_code,
            b.document_type,b.status as record_status,NULL as transaction_status,
            b.total_amount as amount,$bb,b.vat_amount,b.taxable_amount as vatable_sales,b.vat_exempt_amount as vat_exempt_sales,b.zero_rated_amount as zero_rated_sales,0 as sspt_sales,
            (b.taxable_amount+b.vat_exempt_amount+b.zero_rated_amount) as net_amount,
            b.issue_date as business_date,COALESCE(b.issue_date,b.created_at) as created_at,b.created_at as recorded_at,
            NULL as payment_method,NULL as region,$bbranch as branch,NULL as channel,b.buyer_name as customer_name,b.buyer_tin as customer_tin,b.original_invoice_id,
            0 as payment_count,b.amount_paid as payment_amount");
        $orphans = DB::table('merchant_transactions as t')->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('transaction_receipts as r')->whereColumn('r.id', 't.receipt_id')->whereColumn('r.merchant_id', 't.merchant_id');
        })->selectRaw("t.id,'transaction' as document_source,NULL as document_id,t.id as transaction_id,t.merchant_id,t.transaction_ref,NULL as receipt_id,NULL as receipt_number,
            'Unlinked transaction' as business,NULL as tin,NULL as seller_address,NULL as branch_code,t.rdo_code,
            t.transaction_type as document_type,t.status as record_status,t.status as transaction_status,
            t.amount,'regular' as sale_category,0 as statutory_discount_amount,0 as vat_relief_amount,0 as discount_amount,t.vat_amount,t.vatable_sales,0 as vat_exempt_sales,0 as zero_rated_sales,0 as sspt_sales,t.net_amount,
            NULL as business_date,t.created_at,t.created_at as recorded_at,t.payment_method,t.region,t.branch,t.channel,t.customer_name,t.customer_tin,NULL as original_invoice_id,
            1 as payment_count,t.amount as payment_amount");

        return DB::query()->fromSub($receipts->unionAll($invoices)->unionAll($orphans), 'documents');
    }

    public function normalizedCandidates(): Builder
    {
        $typed = $this->candidates()->selectRaw("documents.*, CASE WHEN discount_amount>statutory_discount_amount THEN discount_amount-statutory_discount_amount ELSE 0 END as manual_discount_amount, REPLACE(REPLACE(REPLACE(COALESCE(tin,''),'-',''),' ',''),'.','') as tin_digits,
            CASE WHEN record_status IN ('cancelled','canceled','voided','void','refunded','refund') THEN 'cancelled' WHEN record_status IN ('issued','sent','paid','partially_paid','validated','completed','settled','verified','valid','overdue') THEN 'success' WHEN record_status IN ('failed','rejected') THEN 'failed' ELSE 'pending' END as status,
            CASE WHEN document_type IN ('credit_note','credit_memo') THEN 'credit' WHEN document_type IN ('debit_note','debit_memo') THEN 'debit' WHEN document_type IN ('official_receipt','payment_receipt') THEN 'receipt' WHEN document_type IN ('purchase_invoice','delivery_receipt') THEN 'supplementary' ELSE 'invoice' END as document_family");
        $embedded = $this->prefix('00', 'SUBSTR(tin_digits,10,3)');

        return DB::query()->fromSub($typed, 'typed')->selectRaw("typed.*,
            CASE WHEN LENGTH(tin_digits) IN (12,14) THEN SUBSTR(tin_digits,1,9) ELSE tin_digits END as seller_tin_key,
            COALESCE(NULLIF(branch_code,''),CASE WHEN LENGTH(tin_digits)=12 THEN $embedded WHEN LENGTH(tin_digits)=14 THEN SUBSTR(tin_digits,10,5) ELSE '' END) as seller_branch_key,
            UPPER(TRIM(COALESCE(NULLIF(receipt_number,''),id))) as document_number_key");
    }

    public function query(?string $merchantId = null): Builder
    {
        $typed = $this->normalizedCandidates()->when($merchantId !== null, fn ($q) => $q->where('merchant_id', $merchantId));
        // Document identity is independent of payment count, formatting and import time.
        $identity = "CASE WHEN seller_tin_key='' THEN merchant_id ELSE '' END, seller_tin_key, seller_branch_key, document_family, document_number_key";
        $ranked = DB::query()->fromSub($typed, 'normalized')->selectRaw("normalized.*,
            COUNT(*) OVER (PARTITION BY $identity) as identity_count,
            ROW_NUMBER() OVER (PARTITION BY $identity ORDER BY CASE WHEN document_source='invoice' THEN 0 ELSE 1 END,document_id,id) as identity_rank");

        return DB::query()->fromSub($ranked, 'ranked')->where('identity_rank', 1)->selectRaw("ranked.*,
            CASE WHEN status='cancelled' THEN 'cancelled' WHEN document_family IN ('credit','debit') THEN 'notes' WHEN document_family IN ('receipt','supplementary') THEN 'receipts' ELSE 'invoices' END as category");
    }
}
