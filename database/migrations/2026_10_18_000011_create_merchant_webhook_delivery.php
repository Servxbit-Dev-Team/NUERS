<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('merchant_webhook_endpoints')) {
            Schema::create('merchant_webhook_endpoints', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('merchant_id', 80)->index();
                $t->string('environment', 8);
                $t->string('name', 120);
                $t->text('encrypted_url');
                $t->char('url_hash', 64);
                $t->string('host', 253);
                $t->text('events');
                $t->text('encrypted_secret');
                $t->string('secret_last_four', 4);
                $t->unsignedInteger('secret_version')->default(1);
                $t->unsignedInteger('revision')->default(1);
                $t->string('status', 20)->default('pending');
                $t->dateTime('verified_at')->nullable();
                $t->uuid('verification_delivery_id')->nullable();
                $t->dateTime('last_verification_requested_at')->nullable();
                $t->uuid('request_id')->unique();
                $t->char('request_hash', 64);
                $t->timestamps();
                $t->index(['merchant_id','environment','status'], 'mwh_endpoint_scope');
            });
        }
        if (! Schema::hasTable('merchant_webhook_deliveries')) {
            Schema::create('merchant_webhook_deliveries', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('endpoint_id')->index();
                $t->string('merchant_id', 80);
                $t->string('environment', 8);
                $t->char('event_key', 64)->unique();
                $t->string('event_type', 60);
                $t->string('subject_id', 80);
                $t->text('encrypted_body');
                $t->char('body_hash', 64);
                $t->unsignedInteger('secret_version');
                $t->string('state', 20)->default('pending');
                $t->unsignedInteger('attempts')->default(0);
                $t->unsignedInteger('cycle_attempts')->default(0);
                $t->unsignedInteger('manual_retries')->default(0);
                $t->dateTime('available_at');
                $t->dateTime('retry_deadline_at');
                $t->uuid('lease_token')->nullable();
                $t->dateTime('lease_until')->nullable();
                $t->unsignedSmallInteger('last_http_status')->nullable();
                $t->string('last_error', 80)->nullable();
                $t->dateTime('delivered_at')->nullable();
                $t->timestamps();
                $t->index(['state','available_at'], 'mwh_claim');
                $t->index(['merchant_id','environment','created_at'], 'mwh_delivery_scope');
            });
        }
        if (! Schema::hasTable('merchant_webhook_attempts')) {
            Schema::create('merchant_webhook_attempts', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('delivery_id')->index();
                $t->unsignedInteger('number');
                $t->string('state', 20);
                $t->unsignedSmallInteger('http_status')->nullable();
                $t->string('error_code', 80)->nullable();
                $t->unsignedInteger('latency_ms')->nullable();
                $t->dateTime('started_at');
                $t->dateTime('finished_at')->nullable();
                $t->unique(['delivery_id','number'], 'mwh_attempt_unique');
            });
        }
        if (! Schema::hasTable('merchant_webhook_runtime')) {
            Schema::create('merchant_webhook_runtime', function (Blueprint $t) {
                $t->string('id', 30)->primary();
                $t->dateTime('last_seen_at')->nullable();
                $t->string('state', 20);
            });
        }
    }
    public function down(): void
    {
        throw new RuntimeException('Forward-only: preserve callback configuration and delivery audit history.');
    }
};
