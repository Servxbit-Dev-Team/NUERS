<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('profiles', 'tin')) {
            Schema::table('profiles', function (Blueprint $table): void {
                $table->string('tin')->nullable()->after('organization')->index();
            });
        }

        if (! Schema::hasColumn('profiles', 'tin_bound_at')) {
            Schema::table('profiles', function (Blueprint $table): void {
                $table->timestamp('tin_bound_at')->nullable()->after('tin');
            });
        }

        if (Schema::hasTable('client_accounts') && ! Schema::hasColumn('client_accounts', 'tin')) {
            Schema::table('client_accounts', function (Blueprint $table): void {
                $table->string('tin')->nullable()->after('mobile')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('client_accounts') && Schema::hasColumn('client_accounts', 'tin')) {
            Schema::table('client_accounts', function (Blueprint $table): void {
                $table->dropColumn('tin');
            });
        }

        if (Schema::hasColumn('profiles', 'tin_bound_at')) {
            Schema::table('profiles', function (Blueprint $table): void {
                $table->dropColumn('tin_bound_at');
            });
        }

        if (Schema::hasColumn('profiles', 'tin')) {
            Schema::table('profiles', function (Blueprint $table): void {
                $table->dropColumn('tin');
            });
        }
    }
};
