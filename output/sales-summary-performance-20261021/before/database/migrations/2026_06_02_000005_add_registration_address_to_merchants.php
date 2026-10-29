<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            if (!Schema::hasColumn('merchants', 'business_registration_address')) {
                $table->text('business_registration_address')->nullable()->after('address');
            }

            if (!Schema::hasColumn('merchants', 'country')) {
                $table->string('country')->default('Philippines')->after('business_registration_address');
            }

            if (!Schema::hasColumn('merchants', 'country_code')) {
                $table->string('country_code', 8)->default('PH')->after('country');
            }

            if (!Schema::hasColumn('merchants', 'city_code')) {
                $table->string('city_code')->nullable()->after('city');
            }

            if (!Schema::hasColumn('merchants', 'barangay')) {
                $table->string('barangay')->nullable()->after('city_code');
            }

            if (!Schema::hasColumn('merchants', 'barangay_code')) {
                $table->string('barangay_code')->nullable()->after('barangay');
            }

            if (!Schema::hasColumn('merchants', 'rdo_code')) {
                $table->string('rdo_code', 20)->nullable()->after('zip_code');
            }

            if (!Schema::hasColumn('merchants', 'rdo_name')) {
                $table->string('rdo_name')->nullable()->after('rdo_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            foreach ([
                'rdo_name',
                'rdo_code',
                'barangay_code',
                'barangay',
                'city_code',
                'country_code',
                'country',
                'business_registration_address',
            ] as $column) {
                if (Schema::hasColumn('merchants', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
