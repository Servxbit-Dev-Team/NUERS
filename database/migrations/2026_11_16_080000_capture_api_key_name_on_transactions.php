<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('merchant_transactions', fn (Blueprint $t) => $t->string('source_api_key_name')->nullable()); }
    public function down(): void { Schema::table('merchant_transactions', fn (Blueprint $t) => $t->dropColumn('source_api_key_name')); }
};
