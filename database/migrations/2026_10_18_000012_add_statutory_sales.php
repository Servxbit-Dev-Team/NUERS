<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        foreach (['business_invoices', 'transaction_receipts', 'integration_sandbox_events'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (!Schema::hasColumn($table, 'sale_category')) {
                    $t->string('sale_category', 40)->default('regular')->index();
                }
                if (!Schema::hasColumn($table, 'sales_benefit')) {
                    $t->json('sales_benefit')->nullable();
                }
                if (!Schema::hasColumn($table, 'statutory_discount_amount')) {
                    $t->decimal('statutory_discount_amount', 18, 2)->default(0);
                }
                if (!Schema::hasColumn($table, 'vat_relief_amount')) {
                    $t->decimal('vat_relief_amount', 18, 2)->default(0);
                }
            });
        }
        if (!Schema::hasColumn('business_invoice_items', 'benefit_details')) {
            Schema::table('business_invoice_items', fn (Blueprint $t) => $t->json('benefit_details')->nullable());
        }
    }
    public function down(): void
    {
        Schema::table('business_invoice_items', fn (Blueprint $t) => $t->dropColumn('benefit_details'));
        foreach (['business_invoices', 'transaction_receipts', 'integration_sandbox_events'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['sale_category', 'sales_benefit', 'statutory_discount_amount', 'vat_relief_amount']));
        }
    }
};
