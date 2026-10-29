<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            if (! Schema::hasColumn('merchants', 'logo_url')) {
                $table->string('logo_url', 2048)->nullable()->after('website');
            }
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            if (Schema::hasColumn('merchants', 'logo_url')) {
                $table->dropColumn('logo_url');
            }
        });
    }
};
