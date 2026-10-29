<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_api_keys', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('merchant_id', 36)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('environment')->default('live')->index();
            $table->string('key_prefix')->index();
            $table->string('key_hash')->unique();
            $table->string('key_last_four', 8);
            $table->string('status')->default('active')->index();
            $table->json('scopes')->nullable();
            $table->json('ip_whitelist')->nullable();
            $table->unsignedInteger('rate_limit_per_day')->default(10000);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->cascadeOnDelete();
        });

        Schema::create('merchant_api_request_logs', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('merchant_api_key_id', 36)->nullable()->index();
            $table->char('merchant_id', 36)->nullable()->index();
            $table->string('endpoint')->index();
            $table->string('method', 12)->default('POST');
            $table->unsignedSmallInteger('status_code')->default(200)->index();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('request_reference')->nullable()->index();
            $table->string('source_system')->nullable()->index();
            $table->string('ip_address')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('merchant_api_key_id')->references('id')->on('merchant_api_keys')->nullOnDelete();
            $table->foreign('merchant_id')->references('id')->on('merchants')->nullOnDelete();
        });

        Schema::create('merchant_webhooks', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('merchant_id', 36)->index();
            $table->string('url');
            $table->json('events')->nullable();
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('deliveries')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->cascadeOnDelete();
        });

        Schema::table('merchant_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('merchant_transactions', 'source_system')) {
                $table->string('source_system')->nullable()->index()->after('channel');
            }

            if (! Schema::hasColumn('merchant_transactions', 'tax_type')) {
                $table->string('tax_type')->nullable()->index()->after('transaction_type');
            }

            if (! Schema::hasColumn('merchant_transactions', 'external_payload')) {
                $table->json('external_payload')->nullable()->after('receipt_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('merchant_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('merchant_transactions', 'external_payload')) {
                $table->dropColumn('external_payload');
            }

            if (Schema::hasColumn('merchant_transactions', 'tax_type')) {
                $table->dropColumn('tax_type');
            }

            if (Schema::hasColumn('merchant_transactions', 'source_system')) {
                $table->dropColumn('source_system');
            }
        });

        Schema::dropIfExists('merchant_webhooks');
        Schema::dropIfExists('merchant_api_request_logs');
        Schema::dropIfExists('merchant_api_keys');
    }
};
