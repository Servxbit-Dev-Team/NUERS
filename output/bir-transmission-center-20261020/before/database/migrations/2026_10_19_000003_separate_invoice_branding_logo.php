<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('merchants', 'invoice_logo_url')) {
            Schema::table('merchants', fn (Blueprint $table) => $table->string('invoice_logo_url', 2048)->nullable());
            // Preserve the existing receipt appearance once; future profile uploads are independent.
            DB::table('merchants')->whereNotNull('logo_url')->update(['invoice_logo_url' => DB::raw('logo_url')]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve independent receipt branding and uploaded originals.');
    }
};
