<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['business_invoices', 'transaction_receipts'] as $name) {
            Schema::table($name, function (Blueprint $t) use ($name) {
                $t->string('seller_branch_code', 20)->nullable();
                if ($name === 'business_invoices') {
                    $t->string('seller_rdo_code', 20)->nullable();
                }
            });
        }
        Schema::table('merchant_transactions', function (Blueprint $t) {
            $t->string('source_request_hash', 64)->nullable();
            $t->unique(['merchant_id', 'transaction_ref'], 'merchant_external_reference_unique');
        });
        Schema::table('eis_invoice_payloads', function (Blueprint $t) {
            $t->string('source_document_type', 20)->nullable();
            $t->string('source_document_id', 80)->nullable()->index();
        });
        Schema::table('eis_acknowledgements', function (Blueprint $t) {
            $t->timestamp('verified_at')->nullable();
            $t->string('verified_response_hash', 64)->nullable();
        });
        Schema::create('eis_reporting_obligations', function (Blueprint $t) {
            $t->id();
            $t->string('merchant_id', 80);
            $t->string('branch_code', 20)->default('');
            $t->string('coverage_status', 20)->default('unknown');
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->text('evidence_reference');
            $t->string('verified_by');
            $t->timestamp('verified_at');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->unique(['merchant_id', 'branch_code']);
        });
        Schema::create('eis_document_outbox', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80)->index();
            $t->string('document_source', 20);
            $t->string('document_id', 80);
            $t->string('internal_schema')->default('NUERS-normalized-document-1');
            $t->longText('encrypted_document');
            $t->string('document_hash', 64);
            $t->string('status')->default('blocked_pending_api')->index();
            $t->json('blockers');
            $t->timestamp('prepared_at');
            $t->timestamps();
            $t->unique(['document_source', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::table('merchant_transactions', function (Blueprint $t) {
            $t->dropUnique('merchant_external_reference_unique');
            $t->dropColumn('source_request_hash');
        });
        Schema::dropIfExists('eis_document_outbox');
        Schema::dropIfExists('eis_reporting_obligations');
        Schema::table('eis_acknowledgements', fn (Blueprint $t) => $t->dropColumn(['verified_at', 'verified_response_hash']));
        Schema::table('eis_invoice_payloads', fn (Blueprint $t) => $t->dropColumn(['source_document_type', 'source_document_id']));
        foreach (['business_invoices', 'transaction_receipts'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn($name === 'business_invoices' ? ['seller_branch_code', 'seller_rdo_code'] : ['seller_branch_code']));
        }
    }
};
