<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('merchant_invoice_settings', function (Blueprint $t) {
            $t->uuid('merchant_id')->primary();
            $t->json('settings');
            $t->unsignedInteger('revision')->default(1);
            $t->string('updated_by');
            $t->timestamps();
        });
        Schema::table('business_invoices', fn (Blueprint $t) => $t->json('presentation_settings')->nullable());
    }
    public function down(): void
    {
        Schema::table('business_invoices', fn (Blueprint $t) => $t->dropColumn('presentation_settings'));
        Schema::dropIfExists('merchant_invoice_settings');
    }
};
