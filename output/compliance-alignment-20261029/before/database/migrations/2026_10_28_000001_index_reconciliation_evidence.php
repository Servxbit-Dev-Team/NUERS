<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['reconciliation_prefix', 'reconciliation_suffix'] as $column) {
            if (!Schema::hasColumn('ledger_documents', $column)) Schema::table('ledger_documents', fn (Blueprint $t) => $t->string($column, 80)->nullable());
        }
        if (!Schema::hasColumn('ledger_documents', 'is_draft')) {
            Schema::table('ledger_documents', fn (Blueprint $t) => $t->boolean('is_draft')->default(false));
            DB::table('ledger_documents')->where('record_status', 'draft')->update(['is_draft' => true]);
        }
        foreach ([
            'ledger_reconciliation_pending' => ['reconciliation_prefix', 'projection_id'],
            'ledger_reconciliation_summary' => ['identity_rank', 'is_draft', 'reconciliation_prefix', 'reconciliation_suffix', 'identity_count', 'document_family', 'status', 'amount', 'vat_amount', 'vatable_sales', 'id', 'created_at', 'business'],
            'ledger_transaction_summary' => ['identity_rank', 'is_draft', 'category', 'status', 'identity_count', 'document_family', 'amount', 'vat_amount', 'manual_discount_amount', 'statutory_discount_amount', 'payment_count', 'payment_amount'],
            'ledger_eis_order' => ['identity_rank', 'is_draft', 'business_date', 'id', 'document_source'],
            'ledger_eis_summary' => ['identity_rank', 'is_draft', 'document_source', 'amount'],
            'ledger_id' => ['id'],
            'ledger_merchant_overview' => ['merchant_id', 'tenant_identity_rank', 'is_draft', 'business_day', 'tenant_identity_count', 'financial_valid', 'status', 'document_family', 'amount', 'original_invoice_id', 'document_source', 'document_id'],
            'ledger_global_amount' => ['identity_rank', 'amount', 'id'],
            'ledger_global_received' => ['identity_rank', 'recorded_at', 'id'],
        ] as $name => $columns) {
            if (!Schema::hasIndex('ledger_documents', $name)) Schema::table('ledger_documents', fn (Blueprint $t) => $t->index($columns, $name));
        }
        if (!Schema::hasIndex('merchant_transactions', 'mt_registry_summary')) Schema::table('merchant_transactions', fn (Blueprint $t) => $t->index(['merchant_id', 'status', 'amount', 'created_at'], 'mt_registry_summary'));
        // Existing source/payload/original-reference triggers already queue these
        // documents. This migration adds only derivative fields and indexes.
        DB::table('ledger_dirty_documents')->insertOrIgnoreUsing(['source', 'document_id'],
            DB::table('ledger_documents')->whereNull('reconciliation_prefix')->selectRaw('document_source,COALESCE(document_id,transaction_id)'));
    }

    public function down(): void
    {
        Schema::table('merchant_transactions', fn (Blueprint $t) => $t->dropIndex('mt_registry_summary'));
        Schema::table('ledger_documents', function (Blueprint $t) {
            foreach (['ledger_reconciliation_pending', 'ledger_reconciliation_summary', 'ledger_global_amount', 'ledger_global_received', 'ledger_transaction_summary', 'ledger_eis_order', 'ledger_eis_summary', 'ledger_id', 'ledger_merchant_overview'] as $index) $t->dropIndex($index);
            $t->dropColumn(['reconciliation_prefix', 'reconciliation_suffix', 'is_draft']);
        });
    }
};
