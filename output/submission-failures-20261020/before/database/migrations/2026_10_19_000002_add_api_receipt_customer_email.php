<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transaction_receipts', 'buyer_email')) {
            Schema::table('transaction_receipts', fn (Blueprint $table) => $table->string('buyer_email', 254)->nullable());
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve customer receipt delivery records.');
    }
};
