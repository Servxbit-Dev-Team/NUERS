<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['business_invoices', 'transaction_receipts', 'invoice_series'] as $table) {
            if (!Schema::hasColumn($table, 'document_registration')) Schema::table($table, fn (Blueprint $t) => $t->text('document_registration')->nullable());
        }
        if (!Schema::hasColumn('invoice_series', 'serial_digits')) Schema::table('invoice_series', fn (Blueprint $t) => $t->unsignedTinyInteger('serial_digits')->default(8));
        if (!Schema::hasColumn('business_invoices', 'buyer_customer_type')) Schema::table('business_invoices', fn (Blueprint $t) => $t->string('buyer_customer_type', 20)->nullable());
    }
    public function down(): void { /* Preserve issued registration snapshots and numbering evidence. */ }
};
