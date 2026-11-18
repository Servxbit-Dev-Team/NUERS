<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('merchant_wallet_adjustments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80)->index();
            $t->uuid('request_id')->unique();
            $t->char('request_hash', 64);
            $t->string('kind', 20);
            $t->unsignedBigInteger('amount_centavos');
            $t->string('payment_method', 30);
            $t->text('reference_encrypted');
            $t->char('settlement_hash', 64)->unique();
            $t->date('settled_on');
            $t->text('reason_encrypted');
            $t->string('actor_id', 100);
            $t->uuid('ledger_entry_id')->unique();
            $t->dateTime('created_at');
            $t->index(['merchant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Wallet adjustment history must be preserved through a reviewed forward migration.');
    }
};
