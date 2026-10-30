<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class () extends Migration {
    public function up(): void
    {
        // MySQL DDL can commit before a later index statement fails. Resume only
        // this additive schema; retain every existing test record and audit row.
        if (!Schema::hasTable('integration_sandbox_events')) {
            Schema::create('integration_sandbox_events', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('merchant_id', 80)->index();
                $t->string('api_key_id', 80)->index();
                $t->string('environment', 10)->default('sandbox');
                $t->string('branch_code', 5);
                $t->string('source_system', 120);
                $t->string('source_event_id', 120);
                $t->string('external_reference', 120);
                $t->string('receipt_number', 120);
                $t->string('document_type', 40);
                $t->string('event_type', 40);
                $t->char('receipt_hash', 64)->unique();
                $t->char('namespace_hash', 64)->unique();
                $t->char('event_hash', 64)->unique();
                $t->char('request_hash', 64);
                $t->unsignedBigInteger('gross_centavos');
                $t->longText('details_encrypted');
                $t->dateTime('occurred_at');
                $t->timestamps();
            });
        }
        if (!Schema::hasColumns('integration_sandbox_events', ['receipt_hash','namespace_hash','event_hash','request_hash','details_encrypted','environment','merchant_id','branch_code','created_at'])) {
            throw new RuntimeException('Sandbox schema requires independent review.');
        }
        if (!Schema::hasIndex('integration_sandbox_events', 'sandbox_merchant_location_date')) {
            Schema::table('integration_sandbox_events', fn (Blueprint $t) => $t->index(['merchant_id','branch_code','created_at'], 'sandbox_merchant_location_date'));
        }
        // Avoid MySQL's legacy implicit first-TIMESTAMP update behavior.
        if (DB::getDriverName() === 'mysql' && Schema::getColumnType('integration_sandbox_events', 'occurred_at') === 'timestamp') {
            DB::statement('ALTER TABLE integration_sandbox_events MODIFY occurred_at DATETIME NOT NULL');
        }
        foreach (['previous_minimum_transaction_centavos','minimum_transaction_centavos'] as $column) {
            if (!Schema::hasColumn('wallet_pricing_history', $column)) {
                Schema::table('wallet_pricing_history', fn (Blueprint $t) => $t->unsignedBigInteger($column)->nullable());
            }
        }
    }
    public function down(): void
    {
        throw new RuntimeException('Test record and pricing history retention require a reviewed forward migration.');
    }
};
