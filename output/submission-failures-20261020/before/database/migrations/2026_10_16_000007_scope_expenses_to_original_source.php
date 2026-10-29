<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_expenses', function (Blueprint $t) {
            $t->string('source_invoice_id', 80)->nullable();
            $t->unique(['merchant_id', 'source_invoice_id'], 'expense_original_source_unique');
            $t->dropUnique('merchant_expenses_source_invoice_reference_buyer_tin_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve source-scoped expense provenance and original records.');
    }
};
