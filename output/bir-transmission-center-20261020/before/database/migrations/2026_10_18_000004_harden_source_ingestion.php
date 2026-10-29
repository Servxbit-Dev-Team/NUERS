<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('integration_source_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80)->index();
            $t->string('branch_code', 5);
            $t->string('source_system', 120);
            $t->boolean('vat_registered');
            $t->string('seller_name');
            $t->string('seller_tin', 20);
            $t->text('seller_address');
            $t->text('authority_reference');
            $t->text('evidence_reference');
            $t->dateTime('valid_from');
            $t->dateTime('valid_until');
            $t->string('status', 20)->default('active');
            $t->string('reviewed_by', 80);
            $t->dateTime('reviewed_at');
            $t->uuid('request_id')->unique();
            $t->char('request_hash', 64);
            $t->text('revocation_reason')->nullable();
            $t->timestamps();
            $t->index(['merchant_id', 'branch_code', 'source_system'], 'source_profile_lookup');
        });
        Schema::table('transaction_receipts', function (Blueprint $t) {
            $t->text('buyer_address')->nullable();
            $t->decimal('withholding_tax', 18, 2)->nullable();
            $t->text('withholding_basis_reference')->nullable();
            $t->decimal('sspt_sales', 18, 2)->default(0);
            $t->string('record_mode', 30)->nullable();
            $t->uuid('source_profile_id')->nullable();
        });
    }
    public function down(): void { throw new RuntimeException('Forward-only: preserve source and financial evidence.'); }
};
