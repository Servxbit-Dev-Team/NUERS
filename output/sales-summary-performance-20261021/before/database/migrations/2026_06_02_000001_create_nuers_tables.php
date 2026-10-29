<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->foreign('id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('email')->unique();
            $table->string('role')->default('merchant');
            $table->string('full_name')->default('');
            $table->string('organization')->default('');
            $table->timestamps();
        });

        Schema::create('merchants', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignId('merchant_account_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tin')->nullable()->index();
            $table->string('business_name');
            $table->string('owner_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('zip_code')->nullable();
            $table->string('sector')->default('retail')->index();
            $table->string('region')->default('NCR')->index();
            $table->string('business_type')->default('corporation');
            $table->date('bir_registration_date')->nullable();
            $table->boolean('vat_registered')->default(false);
            $table->string('status')->default('pending')->index();
            $table->unsignedTinyInteger('compliance_score')->default(0);
            $table->decimal('monthly_revenue', 18, 2)->default(0);
            $table->decimal('annual_revenue', 18, 2)->default(0);
            $table->unsignedInteger('employee_count')->default(0);
            $table->string('pos_system')->nullable();
            $table->string('merchant_account_email')->nullable()->index();
            $table->text('notes')->nullable();
            $table->date('last_audit_date')->nullable();
            $table->date('next_audit_date')->nullable();
            $table->date('registration_date')->nullable();
            $table->string('website')->nullable();
            $table->unsignedInteger('branch_count')->default(1);
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('title');
            $table->text('message');
            $table->string('type')->default('info')->index();
            $table->string('priority')->default('low')->index();
            $table->boolean('is_read')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('merchant_transactions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('merchant_id', 36)->nullable()->index();
            $table->string('merchant_ref_id')->nullable();
            $table->string('transaction_ref')->nullable()->index();
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('vat_amount', 18, 2)->default(0);
            $table->decimal('vatable_sales', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2)->default(0);
            $table->string('payment_method')->default('cash')->index();
            $table->string('region')->default('NCR')->index();
            $table->string('branch')->nullable();
            $table->string('channel')->default('pos');
            $table->string('transaction_type')->default('sale');
            $table->string('customer_name')->nullable();
            $table->string('customer_tin')->nullable();
            $table->string('status')->default('completed')->index();
            $table->char('receipt_id', 36)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('transaction_receipts', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('transaction_id', 36)->nullable()->index();
            $table->char('merchant_id', 36)->nullable()->index();
            $table->string('receipt_number')->index();
            $table->string('series_number')->nullable();
            $table->string('bir_accreditation')->nullable();
            $table->string('receipt_type')->default('official_receipt');
            $table->string('merchant_name');
            $table->string('merchant_tin');
            $table->text('merchant_address')->nullable();
            $table->string('merchant_vat_reg')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_tin')->nullable();
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('vatable_sales', 18, 2)->default(0);
            $table->decimal('vat_exempt_sales', 18, 2)->default(0);
            $table->decimal('zero_rated_sales', 18, 2)->default(0);
            $table->decimal('vat_amount', 18, 2)->default(0);
            $table->decimal('total_due', 18, 2)->default(0);
            $table->json('items')->nullable();
            $table->string('status')->default('issued')->index();
            $table->timestamp('issued_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_receipts');
        Schema::dropIfExists('merchant_transactions');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('merchants');
        Schema::dropIfExists('profiles');
    }
};
