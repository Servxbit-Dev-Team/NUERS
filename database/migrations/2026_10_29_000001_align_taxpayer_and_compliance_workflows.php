<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('taxpayer_authorizations', function (Blueprint $t) {
            $t->dropUnique(['kind', 'reference']);
            $t->string('system_reference', 160)->nullable();
            $t->string('branch_notification_reference', 1000)->nullable();
            $t->unique(['merchant_id', 'environment', 'kind', 'reference', 'branch_code'], 'permit_branch_identity');
        });
        Schema::create('merchant_compliance_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id');
            $t->string('kind', 24);
            $t->string('reference', 120);
            $t->string('status', 24)->default('draft');
            $t->date('business_date');
            $t->string('branch_code', 5)->nullable();
            $t->string('related_source', 24)->nullable();
            $t->string('related_id')->nullable();
            $t->unsignedInteger('source_sequence')->nullable();
            $t->bigInteger('amount_centavos')->default(0);
            $t->longText('payload');
            $t->char('payload_hash', 64);
            $t->uuid('request_id');
            $t->char('request_hash', 64);
            $t->unsignedInteger('revision')->default(1);
            $t->string('created_by');
            $t->timestamps();
            $t->unique(['merchant_id', 'request_id'], 'compliance_request_identity');
            $t->unique(['merchant_id', 'kind', 'reference'], 'compliance_document_identity');
            $t->index(['merchant_id', 'kind', 'business_date', 'id'], 'compliance_merchant_list');
            $t->index(['kind', 'business_date', 'id'], 'compliance_admin_list');
            $t->index(['merchant_id', 'related_source', 'related_id'], 'compliance_source_lookup');
        });
        Schema::create('service_fee_invoice_entries', function (Blueprint $t) {
            $t->string('wallet_entry_id')->primary();
            $t->uuid('compliance_record_id')->index();
            $t->timestamps();
        });
        Schema::table('business_invoices', function (Blueprint $t) {
            $t->text('withholding_details')->nullable();
            $t->uuid('manual_recovery_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        // Financial evidence and shared PTI references cannot safely be collapsed automatically.
        throw new RuntimeException('Restore the reviewed recovery snapshot; this evidence migration is forward-only.');
    }
};
