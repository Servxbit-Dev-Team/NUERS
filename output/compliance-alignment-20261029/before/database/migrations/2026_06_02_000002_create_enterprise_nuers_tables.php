<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('portal')->index();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('module')->index();
            $table->string('action')->index();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('bir_accounts', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('employee_id')->unique();
            $table->string('full_name');
            $table->string('email')->index();
            $table->string('region')->default('National Office')->index();
            $table->string('office')->default('Revenue Monitoring');
            $table->string('role_title')->default('Revenue Officer');
            $table->string('clearance_level')->default('regulator')->index();
            $table->boolean('mfa_enabled')->default(true);
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });

        Schema::create('client_accounts', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('account_number')->unique();
            $table->string('full_name');
            $table->string('account_type')->default('citizen')->index();
            $table->string('email')->nullable()->index();
            $table->string('mobile')->nullable()->index();
            $table->decimal('wallet_balance', 18, 2)->default(0);
            $table->boolean('mfa_enabled')->default(false);
            $table->json('notification_preferences')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });

        Schema::create('agency_accounts', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name');
            $table->string('agency_type')->index();
            $table->string('region')->default('NCR')->index();
            $table->string('lgu_code')->nullable()->index();
            $table->string('subscription_plan')->default('enterprise');
            $table->unsignedBigInteger('monthly_volume')->default(0);
            $table->unsignedTinyInteger('compliance_score')->default(0);
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });

        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('provider');
            $table->string('channel')->index();
            $table->string('status')->default('online')->index();
            $table->string('settlement_window')->default('T+1');
            $table->decimal('fee_rate', 8, 4)->default(0);
            $table->decimal('uptime_percentage', 5, 2)->default(99.00);
            $table->timestamp('last_health_check_at')->nullable();
            $table->json('configuration')->nullable();
            $table->timestamps();
        });

        Schema::create('api_clients', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('owner_type')->index();
            $table->string('owner_id')->nullable()->index();
            $table->string('name');
            $table->string('client_key')->unique();
            $table->json('scopes')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->default(600);
            $table->string('status')->default('active')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('account_type')->index();
            $table->string('account_id')->index();
            $table->string('plan_name')->default('Enterprise');
            $table->string('status')->default('active')->index();
            $table->string('billing_cycle')->default('monthly');
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamp('next_billing_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settlement_batches', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('batch_number')->unique();
            $table->char('gateway_id', 36)->nullable()->index();
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2)->default(0);
            $table->unsignedInteger('transaction_count')->default(0);
            $table->string('status')->default('pending')->index();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email')->nullable()->index();
            $table->string('event_type')->index();
            $table->string('auditable_type')->nullable()->index();
            $table->string('auditable_id')->nullable()->index();
            $table->string('ip_address')->nullable();
            $table->string('device_fingerprint')->nullable();
            $table->string('severity')->default('info')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('fraud_signals', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('merchant_id', 36)->nullable()->index();
            $table->string('signal_type')->index();
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->string('severity')->default('low')->index();
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('status')->default('open')->index();
            $table->text('recommendation')->nullable();
            $table->timestamp('detected_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('ai_insights', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('model')->default('nuers-risk-engine');
            $table->string('insight_type')->index();
            $table->string('title');
            $table->text('summary');
            $table->decimal('confidence_score', 5, 2)->default(0);
            $table->string('impact_area')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('receipt_verifications', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('receipt_id', 36)->nullable()->index();
            $table->string('receipt_number')->index();
            $table->string('verification_method')->default('receipt_number')->index();
            $table->string('verifier_ip')->nullable();
            $table->string('status')->default('verified')->index();
            $table->boolean('signature_valid')->default(true);
            $table->timestamp('verified_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_verifications');
        Schema::dropIfExists('ai_insights');
        Schema::dropIfExists('fraud_signals');
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('settlement_batches');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('api_clients');
        Schema::dropIfExists('payment_gateways');
        Schema::dropIfExists('agency_accounts');
        Schema::dropIfExists('client_accounts');
        Schema::dropIfExists('bir_accounts');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
