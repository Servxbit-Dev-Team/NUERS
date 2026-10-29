<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_invoices', fn (Blueprint $t) => $t->boolean('prices_include_vat')->nullable());
        Schema::table('merchant_api_keys', function (Blueprint $t) {
            $t->timestamp('expires_at')->nullable();
        });
        Schema::table('taxpayer_authorizations', function (Blueprint $t) {
            $t->string('branch_code', 5)->nullable();
        });
        Schema::create('integration_inbox', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80)->index();
            $t->string('api_key_id', 80);
            $t->string('namespace_hash', 64)->unique();
            $t->string('event_hash', 64)->unique();
            $t->string('request_hash', 64);
            $t->string('source_system', 120);
            $t->string('source_event_id', 120);
            $t->string('external_reference', 120);
            $t->string('branch_code', 5);
            $t->string('schema_version', 80);
            $t->string('event_type', 80);
            $t->dateTime('occurred_at');
            $t->dateTime('received_at');
            $t->string('transaction_id', 80)->nullable();
            $t->string('receipt_id', 80)->nullable();
            $t->json('response')->nullable();
            $t->timestamps();
            $t->index(['merchant_id', 'source_system', 'source_event_id'], 'inbox_event_lookup');
        });
        Schema::create('api_quota_buckets', function (Blueprint $t) {
            $t->string('id', 64)->primary();
            $t->unsignedBigInteger('used')->default(0);
            $t->dateTime('expires_at')->index();
        });
        Schema::create('invoice_series', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80);
            $t->string('branch_code', 5);
            $t->string('document_family', 40);
            $t->string('prefix', 40);
            $t->unsignedBigInteger('range_start');
            $t->unsignedBigInteger('range_end');
            $t->unsignedBigInteger('last_number')->nullable();
            $t->string('status', 20)->default('pending');
            $t->text('authority_reference');
            $t->string('verified_by', 80);
            $t->dateTime('valid_from');
            $t->dateTime('valid_until');
            $t->timestamps();
            $t->unique(['merchant_id', 'branch_code', 'document_family', 'prefix'], 'series_namespace');
        });
        Schema::create('eis_delivery_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('outbox_id')->unique();
            $t->string('merchant_id', 80)->index();
            $t->string('state', 40)->default('blocked');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('available_at')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->uuid('lease_token')->nullable();
            $t->text('last_error')->nullable();
            $t->string('provider_reference', 255)->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['state', 'available_at'], 'delivery_claim');
        });
        Schema::create('eis_delivery_attempts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('job_id')->index();
            $t->uuid('lease_token');
            $t->string('operation', 20);
            $t->string('outcome', 40);
            $t->string('payload_hash', 64);
            $t->string('response_hash', 64)->nullable();
            $t->text('encrypted_response')->nullable();
            $t->string('provider_reference')->nullable();
            $t->boolean('authenticated')->default(false);
            $t->dateTime('started_at');
            $t->timestamp('finished_at')->nullable();
        });
        Schema::table('reconciliation_reviews', function (Blueprint $t) {
            $t->string('owner_id', 80)->nullable();
            $t->string('priority', 20)->default('normal');
            $t->timestamp('due_at')->nullable();
            $t->text('closure_evidence')->nullable();
        });
        Schema::create('operational_notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('dedupe_key', 64)->unique();
            $t->string('merchant_id', 80)->nullable()->index();
            $t->string('category', 80);
            $t->string('subject_id', 80);
            $t->string('severity', 20);
            $t->text('message');
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamps();
        });
        Schema::create('document_legal_holds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80)->index();
            $t->string('source', 20);
            $t->string('document_id', 80);
            $t->text('authority_reference');
            $t->text('reason');
            $t->string('created_by', 80);
            $t->text('release_reference')->nullable();
            $t->string('released_by', 80)->nullable();
            $t->timestamp('released_at')->nullable();
            $t->timestamps();
        });
        Schema::create('taxpayer_identity_reviews', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('user_id', 80)->index();
            $t->string('tin', 20);
            $t->text('evidence_reference');
            $t->string('status', 20)->default('pending');
            $t->string('reviewed_by', 80)->nullable();
            $t->text('review_reason')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
        Schema::table('merchant_api_request_logs', fn (Blueprint $t) => $t->index(['merchant_api_key_id', 'created_at'],'key_requests_time'));
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: preserve event, authorization, hold and delivery evidence.');
    }
};
