<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_api_request_logs', function (Blueprint $t) {
            $t->string('environment', 16)->nullable();
            $t->string('source_event_id', 120)->nullable();
            $t->string('error_code', 80)->nullable();
            $t->string('outcome', 24)->nullable();
            $t->index(['environment', 'status_code', 'created_at'], 'submission_outcomes_environment');
            $t->index(['merchant_id', 'status_code', 'created_at'], 'submission_outcomes_merchant');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_api_request_logs', function (Blueprint $t) {
            $t->dropIndex('submission_outcomes_environment');
            $t->dropIndex('submission_outcomes_merchant');
            $t->dropColumn(['environment', 'source_event_id', 'error_code', 'outcome']);
        });
    }
};
