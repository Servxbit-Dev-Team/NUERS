<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_wallets', function (Blueprint $t) {
            $t->string('merchant_id', 80)->primary();
            $t->bigInteger('available_centavos')->default(0);
            $t->bigInteger('reserved_centavos')->default(0);
            $t->unsignedBigInteger('sequence')->default(0);
            $t->char('ledger_head', 64)->default(str_repeat('0', 64));
            $t->unsignedBigInteger('low_balance_threshold')->default(100000);
            $t->boolean('alerts_enabled')->default(true);
            $t->boolean('low_alerted')->default(false);
            $t->string('intake_mode', 20)->default('realtime');
            $t->unsignedInteger('revision')->default(1);
            $t->dateTime('updated_at');
        });
        Schema::create('merchant_wallet_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80)->index();
            $t->unsignedBigInteger('sequence');
            $t->string('kind', 20);
            $t->bigInteger('amount_centavos');
            $t->bigInteger('balance_after');
            $t->string('source_type', 30);
            $t->string('source_id', 80);
            $t->string('reference', 160);
            $t->string('branch_code', 5)->nullable();
            $t->string('channel', 30);
            $t->unsignedBigInteger('gross_centavos')->default(0);
            $t->unsignedInteger('pricing_revision')->nullable();
            $t->char('event_key', 64);
            $t->string('actor_id', 100);
            $t->char('previous_hash', 64);
            $t->char('entry_hash', 64);
            $t->dateTime('created_at');
            $t->unique(['merchant_id', 'event_key']);
            $t->unique(['merchant_id', 'sequence']);
            $t->index(['merchant_id', 'created_at']);
        });
        Schema::create('merchant_wallet_topups', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80)->index();
            $t->uuid('request_id');
            $t->char('request_hash', 64);
            $t->unsignedBigInteger('amount_centavos');
            $t->string('payment_method', 30);
            $t->text('payment_reference_encrypted');
            $t->char('payment_reference_hash', 64);
            $t->date('payment_date');
            $t->string('status', 30)->default('pending');
            $t->string('requested_by', 80);
            $t->string('reviewed_by', 80)->nullable();
            $t->text('review_encrypted')->nullable();
            $t->char('settlement_hash', 64)->nullable()->unique();
            $t->char('payment_credit_hash', 64)->nullable()->unique();
            $t->uuid('ledger_entry_id')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('reviewed_at')->nullable();
            $t->unique(['merchant_id', 'request_id']);
            $t->index(['merchant_id', 'created_at']);
        });
        Schema::create('wallet_pricing_history', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedInteger('revision');
            $t->unsignedInteger('previous_fee_centavos');
            $t->unsignedInteger('fee_centavos');
            $t->string('actor_id', 80);
            $t->text('reason');
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Wallet balances and ledger history require a reviewed forward migration.');
    }
};
