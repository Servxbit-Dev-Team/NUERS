<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['business_invoices', 'transaction_receipts'] as $table) Schema::table($table, function (Blueprint $t) {
            $t->string('permit_to_issue_number')->nullable();
            $t->string('esp_accreditation_number')->nullable();
        });
    }
    public function down(): void
    {
        foreach (['business_invoices', 'transaction_receipts'] as $table) Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['permit_to_issue_number', 'esp_accreditation_number']));
    }
};
