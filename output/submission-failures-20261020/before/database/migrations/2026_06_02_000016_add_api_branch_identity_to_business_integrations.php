<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_api_keys', function (Blueprint $table) {
            if (! Schema::hasColumn('merchant_api_keys', 'branch_type')) {
                $table->string('branch_type')->default('main')->index()->after('environment');
            }

            if (! Schema::hasColumn('merchant_api_keys', 'branch_code')) {
                $table->string('branch_code')->default('MAIN')->index()->after('branch_type');
            }

            if (! Schema::hasColumn('merchant_api_keys', 'branch_name')) {
                $table->string('branch_name')->nullable()->index()->after('branch_code');
            }

            if (! Schema::hasColumn('merchant_api_keys', 'branch_location')) {
                $table->string('branch_location')->nullable()->after('branch_name');
            }
        });

        Schema::table('merchant_api_request_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('merchant_api_request_logs', 'branch_type')) {
                $table->string('branch_type')->nullable()->index()->after('source_system');
            }

            if (! Schema::hasColumn('merchant_api_request_logs', 'branch_code')) {
                $table->string('branch_code')->nullable()->index()->after('branch_type');
            }

            if (! Schema::hasColumn('merchant_api_request_logs', 'branch_name')) {
                $table->string('branch_name')->nullable()->index()->after('branch_code');
            }
        });

        Schema::table('merchant_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('merchant_transactions', 'branch_code')) {
                $table->string('branch_code')->nullable()->index()->after('branch');
            }

            if (! Schema::hasColumn('merchant_transactions', 'branch_type')) {
                $table->string('branch_type')->nullable()->index()->after('branch_code');
            }

            if (! Schema::hasColumn('merchant_transactions', 'source_api_key_id')) {
                $table->char('source_api_key_id', 36)->nullable()->index()->after('source_system');
            }
        });

        if (Schema::hasTable('merchant_api_keys')) {
            DB::table('merchant_api_keys')
                ->whereNull('branch_name')
                ->update([
                    'branch_type' => 'main',
                    'branch_code' => 'MAIN',
                    'branch_name' => 'Main Office',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('merchant_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('merchant_transactions', 'source_api_key_id')) {
                $table->dropColumn('source_api_key_id');
            }

            if (Schema::hasColumn('merchant_transactions', 'branch_type')) {
                $table->dropColumn('branch_type');
            }

            if (Schema::hasColumn('merchant_transactions', 'branch_code')) {
                $table->dropColumn('branch_code');
            }
        });

        Schema::table('merchant_api_request_logs', function (Blueprint $table) {
            if (Schema::hasColumn('merchant_api_request_logs', 'branch_name')) {
                $table->dropColumn('branch_name');
            }

            if (Schema::hasColumn('merchant_api_request_logs', 'branch_code')) {
                $table->dropColumn('branch_code');
            }

            if (Schema::hasColumn('merchant_api_request_logs', 'branch_type')) {
                $table->dropColumn('branch_type');
            }
        });

        Schema::table('merchant_api_keys', function (Blueprint $table) {
            if (Schema::hasColumn('merchant_api_keys', 'branch_location')) {
                $table->dropColumn('branch_location');
            }

            if (Schema::hasColumn('merchant_api_keys', 'branch_name')) {
                $table->dropColumn('branch_name');
            }

            if (Schema::hasColumn('merchant_api_keys', 'branch_code')) {
                $table->dropColumn('branch_code');
            }

            if (Schema::hasColumn('merchant_api_keys', 'branch_type')) {
                $table->dropColumn('branch_type');
            }
        });
    }
};
