<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('ledger_projection_state')) {
            Schema::create('ledger_projection_state', function (Blueprint $t) {
                $t->unsignedInteger('id')->primary();
                $t->boolean('ready')->default(false);
                $t->unsignedInteger('version')->default(1);
                $t->unsignedBigInteger('revision')->default(0);
                $t->timestamp('updated_at')->nullable();
            });
        }
        DB::table('ledger_projection_state')->insertOrIgnore(['id' => 1, 'ready' => false, 'version' => 1]);
        if (!Schema::hasTable('ledger_dirty_documents')) {
            Schema::create('ledger_dirty_documents', function (Blueprint $t) {
                $t->bigIncrements('sequence');
                $t->string('source', 16);
                $t->string('document_id', 80);
                $t->unsignedBigInteger('generation')->default(1);
                $t->unique(['source', 'document_id'], 'ledger_dirty_source');
            });
        }
        if (!Schema::hasTable('ledger_documents')) {
            Schema::create('ledger_documents', function (Blueprint $t) {
                $t->string('projection_id', 100)->primary();
                foreach (['id', 'document_id', 'transaction_id', 'merchant_id', 'receipt_id', 'original_invoice_id'] as $c) {
                    $t->string($c, 100)->nullable();
                }
                foreach (['document_source', 'document_family', 'category', 'status', 'sale_category'] as $c) {
                    $t->string($c, 32);
                }
                foreach (['transaction_ref', 'receipt_number', 'business', 'tin', 'branch_code', 'rdo_code', 'document_type', 'record_status', 'transaction_status', 'payment_method', 'region', 'branch', 'channel', 'customer_name', 'customer_tin', 'tin_digits', 'seller_tin_key', 'seller_branch_key', 'document_number_key'] as $c) {
                    $t->string($c, 255)->nullable();
                }
                $t->text('seller_address')->nullable();
                foreach (['amount', 'statutory_discount_amount', 'vat_relief_amount', 'discount_amount', 'manual_discount_amount', 'vat_amount', 'vatable_sales', 'vat_exempt_sales', 'zero_rated_sales', 'sspt_sales', 'net_amount', 'payment_amount'] as $c) {
                    $t->decimal($c, 18, 2)->default(0);
                }
                $t->unsignedInteger('payment_count')->default(0);
                foreach (['business_date', 'created_at', 'recorded_at'] as $c) {
                    $t->dateTime($c)->nullable();
                }
                $t->date('business_day')->nullable();
                $t->char('identity_key', 64);
                $t->char('tenant_identity_key', 64);
                $t->unsignedInteger('identity_count')->default(1);
                $t->unsignedInteger('tenant_identity_count')->default(1);
                $t->unsignedInteger('identity_rank')->default(1);
                $t->unsignedInteger('tenant_identity_rank')->default(1);
                $t->string('rank_key', 255);
                $t->boolean('financial_valid')->default(false);
                $t->bigInteger('non_vat_centavos')->default(0);
                $t->string('verified_branch', 20)->nullable();
                $t->timestamp('projected_at');
                $t->index(['identity_key', 'rank_key'], 'ledger_identity_rank');
                $t->index(['tenant_identity_key', 'rank_key'], 'ledger_tenant_identity_rank');
                $t->index(['identity_rank', 'created_at', 'id'], 'ledger_global_recent');
                $t->index(['identity_rank', 'business_day'], 'ledger_global_period');
                $t->index(['merchant_id', 'tenant_identity_rank', 'business_day'], 'ledger_merchant_period');
                $t->index(['merchant_id', 'tenant_identity_rank', 'created_at', 'id'], 'ledger_merchant_recent');
                $t->index(['merchant_id', 'document_source', 'document_id'], 'ledger_source_owner');
                $t->index(['merchant_id', 'receipt_number'], 'ledger_merchant_number');
                $t->index(['merchant_id', 'status', 'sale_category', 'business_day'], 'ledger_merchant_filters');
                $t->index(['original_invoice_id', 'merchant_id'], 'ledger_original');
                $t->index(['merchant_id', 'tenant_identity_rank', 'verified_branch', 'document_source'], 'ledger_branch_options');
                $t->index(['merchant_id', 'tenant_identity_rank', 'document_type', 'document_source'], 'ledger_type_options');
                $t->index(['merchant_id', 'tenant_identity_rank', 'business_date', 'recorded_at', 'id'], 'ledger_merchant_document_order');
                $t->index(['created_at', 'document_source', 'document_id'], 'ledger_all_document_order');
                $t->index(['merchant_id', 'tenant_identity_rank', 'customer_name', 'document_source'], 'ledger_customer_options');
                $t->index(['merchant_id', 'tenant_identity_rank', 'branch_code', 'branch', 'document_source'], 'ledger_record_branch_options');
                $t->index(['merchant_id', 'tenant_identity_rank', 'channel', 'document_source'], 'ledger_channel_options');
                $t->index(['merchant_id', 'tenant_identity_rank', 'payment_method', 'document_source'], 'ledger_payment_options');
            });
        }
        foreach ($this->indexes() as [$table, $columns, $name]) {
            if (!Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
        if (!Schema::hasTable('sales_report_exports')) {
            Schema::create('sales_report_exports', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('merchant_id', 100);
                $t->string('actor_id', 100);
                $t->string('state', 20)->default('queued');
                $t->text('filters');
                $t->unsignedBigInteger('records')->default(0);
                $t->char('sha256', 64)->nullable();
                $t->string('error', 255)->nullable();
                $t->timestamp('expires_at');
                $t->timestamps();
                $t->index(['merchant_id','actor_id','created_at'], 'sales_export_owner');
                $t->index(['state','created_at'], 'sales_export_queue');
            });
        }
        app(\App\Services\LedgerProjection::class)->installTriggers();
    }

    public function down(): void
    {
        app(\App\Services\LedgerProjection::class)->dropTriggers();
        foreach ($this->indexes() as [$table, $columns, $name]) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
        }
        Schema::dropIfExists('sales_report_exports');
        Schema::dropIfExists('ledger_documents');
        Schema::dropIfExists('ledger_dirty_documents');
        Schema::dropIfExists('ledger_projection_state');
    }

    private function indexes(): array
    {
        return [
            ['merchant_transactions', ['merchant_id', 'created_at', 'id'], 'mt_merchant_created_id'],
            ['merchant_transactions', ['created_at', 'status', 'id'], 'mt_created_status_id'],
            ['merchant_transactions', ['created_at', 'id'], 'mt_recent_order'],
            ['merchant_transactions', ['status', 'amount'], 'mt_status_amount'],
            ['merchant_transactions', ['region'], 'mt_region_options'],
            ['merchant_transactions', ['branch'], 'mt_branch_options'],
            ['merchant_transactions', ['payment_method'], 'mt_payment_options'],
            ['merchant_transactions', ['merchant_id','transaction_type'], 'mt_merchant_type_options'],
            ['merchant_transactions', ['merchant_id','channel'], 'mt_merchant_channel_options'],
            ['merchant_transactions', ['merchant_id','branch'], 'mt_merchant_branch_options'],
            ['merchant_transactions', ['merchant_id','payment_method'], 'mt_merchant_payment_options'],
            ['transaction_receipts', ['status'], 'tr_status_count'],
            ['merchant_transactions', ['receipt_id', 'merchant_id', 'created_at', 'id'], 'mt_receipt_owner_created'],
            ['transaction_receipts', ['merchant_id', 'issued_at', 'id'], 'tr_merchant_issued_id'],
            ['transaction_receipts', ['merchant_id', 'transaction_id'], 'tr_merchant_transaction'],
            ['business_invoices', ['merchant_id', 'status', 'issue_date', 'id'], 'bi_merchant_status_date'],
            ['business_invoices', ['original_invoice_id', 'merchant_id'], 'bi_original_merchant'],
            ['business_invoice_items', ['invoice_id', 'merchant_id', 'line_number'], 'bii_invoice_merchant_line'],
            ['eis_document_outbox', ['merchant_id', 'document_source', 'document_id', 'source_review_revision', 'prepared_at'], 'outbox_owner_source_latest'],
            ['ledger_documents', ['merchant_id', 'tenant_identity_rank', 'recorded_at'], 'ledger_merchant_recorded'],
            ['merchant_wallet_entries', ['kind', 'created_at', 'amount_centavos'], 'mwe_kind_created_amount'],
        ];
    }
};
