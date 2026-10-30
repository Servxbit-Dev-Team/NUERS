<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('bir_eis_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 100);
            $t->string('branch_code', 5);
            $t->string('environment', 20);
            $t->string('system_type', 3);
            $t->string('name', 120);
            $t->string('accreditation_id', 8)->nullable();
            $t->string('application_id', 100)->nullable();
            $t->string('seller_type', 1);
            $t->string('ptu_number', 50)->nullable();
            $t->string('machine_serial', 50)->nullable();
            $t->string('machine_id', 50)->nullable();
            $t->string('schema_dialect', 40)->default('workbook_20260926');
            $t->text('authority_reference')->nullable();
            $t->text('contract_reference')->nullable();
            $t->boolean('source_copies_authorized')->default(false);
            $t->boolean('enabled')->default(false);
            $t->longText('encrypted_credentials')->nullable();
            $t->longText('encrypted_session')->nullable();
            $t->timestamp('session_expires_at')->nullable();
            $t->timestamp('key_expires_at')->nullable();
            $t->string('key_fingerprint', 64)->nullable();
            $t->timestamp('authenticated_at')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->index(['merchant_id', 'branch_code', 'environment']);
            $t->unique(['merchant_id', 'branch_code', 'environment', 'system_type', 'accreditation_id', 'application_id'], 'bir_profile_application_unique');
        });
        Schema::create('bir_eis_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('profile_id');
            $t->string('merchant_id', 100);
            $t->string('environment', 20);
            $t->string('submit_id', 40)->unique();
            $t->uuid('request_id')->unique();
            $t->string('request_fingerprint', 64);
            $t->unsignedInteger('profile_revision');
            $t->string('state', 30)->default('prepared');
            $t->unsignedSmallInteger('document_count');
            $t->string('payload_hash', 64);
            $t->longText('encrypted_payload');
            $t->string('ack_id', 100)->nullable();
            $t->string('error_code', 30)->nullable();
            $t->string('last_error', 500)->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('available_at')->nullable();
            $t->uuid('lease_token')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['state', 'available_at']);
            $t->index(['merchant_id', 'environment', 'created_at']);
        });
        Schema::create('bir_eis_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('batch_id');
            $t->uuid('outbox_id');
            $t->string('merchant_id', 100);
            $t->string('environment', 20);
            $t->string('source', 10);
            $t->string('source_id', 100);
            $t->string('tax_class', 2);
            $t->string('invoice_uid', 24)->unique();
            $t->string('document_number', 50);
            $t->string('source_hash', 64);
            $t->string('result_code', 20)->nullable();
            $t->string('description', 1000)->nullable();
            $t->timestamps();
            $t->string('reporting_identity', 64)->nullable()->unique();
            $t->index(['environment', 'source', 'source_id', 'tax_class'], 'bir_document_reporting_lookup');
            $t->index(['batch_id', 'invoice_uid']);
            $t->index('outbox_id');
        });
        Schema::create('bir_eis_wire_attempts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('profile_id');
            $t->uuid('batch_id')->nullable();
            $t->string('operation', 20);
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->string('error_code', 30)->nullable();
            $t->string('response_hash', 64)->nullable();
            $t->longText('encrypted_response')->nullable();
            $t->timestamp('created_at');
            $t->index(['profile_id', 'created_at']);
            $t->index('batch_id');
        });
        Schema::create('bir_eis_callbacks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('batch_id');
            $t->string('body_hash', 64);
            $t->string('ack_id', 100);
            $t->timestamp('created_at');
            $t->unique(['batch_id', 'body_hash']);
        });
    }

    public function down(): void
    {
        foreach (['bir_eis_callbacks', 'bir_eis_wire_attempts', 'bir_eis_documents', 'bir_eis_batches', 'bir_eis_profiles'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
