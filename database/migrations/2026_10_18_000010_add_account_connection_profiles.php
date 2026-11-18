<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('integration_source_profiles', 'profile_kind')) {
            Schema::table('integration_source_profiles', function (Blueprint $t) {
                $t->string('profile_kind', 30)->default('independently_reviewed');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: preserve original issuer and account connection history.');
    }
};
