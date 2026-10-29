<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_invoices', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('merchant_id', 36)->index();
            $table->string('invoice_number')->index();
            $table->string('document_type')->default('sales_invoice')->index();
            $table->string('status')->default('draft')->index();
            $table->unsignedInteger('version_number')->default(1);
            $table->char('parent_invoice_id', 36)->nullable()->index();
            $table->string('merchant_name');
            $table->string('merchant_tin')->nullable()->index();
            $table->text('merchant_address')->nullable();
            $table->string('buyer_name');
            $table->string('buyer_tin')->nullable()->index();
            $table->text('buyer_address')->nullable();
            $table->string('buyer_email')->nullable();
            $table->date('issue_date')->nullable()->index();
            $table->date('due_date')->nullable()->index();
            $table->date('delivery_date')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('subtotal_amount', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('taxable_amount', 18, 2)->default(0);
            $table->decimal('vat_amount', 18, 2)->default(0);
            $table->decimal('withholding_tax', 18, 2)->default(0);
            $table->decimal('other_charges', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->decimal('amount_paid', 18, 2)->default(0);
            $table->decimal('amount_due', 18, 2)->default(0);
            $table->string('currency')->default('PHP');
            $table->boolean('vat_registered')->default(false);
            $table->decimal('vat_rate', 8, 2)->default(12);
            $table->decimal('zero_rated_amount', 18, 2)->default(0);
            $table->decimal('vat_exempt_amount', 18, 2)->default(0);
            $table->string('reference_number')->nullable()->index();
            $table->string('purchase_order')->nullable()->index();
            $table->string('sales_order')->nullable()->index();
            $table->char('original_invoice_id', 36)->nullable()->index();
            $table->text('correction_reason')->nullable();
            $table->text('qr_payload')->nullable();
            $table->text('digital_signature')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->string('validation_hash')->nullable()->index();
            $table->text('cancellation_reason')->nullable();
            $table->text('cancellation_note')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('formats_generated')->nullable();
            $table->text('notes')->nullable();
            $table->text('terms_and_conditions')->nullable();
            $table->text('footer_note')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'invoice_number']);
        });

        Schema::create('business_invoice_items', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('invoice_id', 36)->index();
            $table->char('merchant_id', 36)->index();
            $table->unsignedInteger('line_number')->default(1);
            $table->string('item_code')->nullable();
            $table->text('description');
            $table->string('unit')->nullable();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('discount_pct', 8, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('taxable_amount', 18, 2)->default(0);
            $table->decimal('vat_rate', 8, 2)->default(12);
            $table->decimal('vat_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->string('tax_type')->default('vatable')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_invoice_items');
        Schema::dropIfExists('business_invoices');
    }
};
