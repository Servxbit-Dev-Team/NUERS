<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_branches', function (Blueprint $table) {
            $table->json('address_details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_branches', fn (Blueprint $table) => $table->dropColumn('address_details'));
    }
};
