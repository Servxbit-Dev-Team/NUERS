<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('profiles', 'profile_photo_url')) {
            Schema::table('profiles', function (Blueprint $table): void {
                $table->string('profile_photo_url', 2048)->nullable()->after('tin_bound_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('profiles', 'profile_photo_url')) {
            Schema::table('profiles', function (Blueprint $table): void {
                $table->dropColumn('profile_photo_url');
            });
        }
    }
};
