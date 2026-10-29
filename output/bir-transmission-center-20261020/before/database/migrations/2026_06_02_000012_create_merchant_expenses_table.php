<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_expenses', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('merchant_id', 36)->nullable()->index();
            $table->char('supplier_merchant_id', 36)->nullable()->index();
            $table->string('source_invoice_reference')->index();
            $table->string('purchase_order_reference')->nullable()->index();
            $table->string('supplier_name');
            $table->string('supplier_tin')->nullable()->index();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_tin')->nullable()->index();
            $table->string('expense_category')->default('General');
            $table->text('description')->nullable();
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->decimal('vatable_amount', 18, 2)->default(0);
            $table->decimal('input_vat_amount', 18, 2)->default(0);
            $table->decimal('withholding_tax_amount', 18, 2)->default(0);
            $table->decimal('net_payable', 18, 2)->default(0);
            $table->string('document_type')->default('b2b_invoice')->index();
            $table->string('payment_status')->default('unpaid')->index();
            $table->string('validation_status')->default('pending_validation')->index();
            $table->string('reconciliation_status')->default('pending')->index();
            $table->string('claim_status')->default('pending_validation')->index();
            $table->string('risk_level')->nullable()->index();
            $table->unsignedSmallInteger('ai_score')->nullable();
            $table->timestamp('issued_at')->nullable()->index();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_invoice_reference', 'buyer_tin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_expenses');
    }
};
