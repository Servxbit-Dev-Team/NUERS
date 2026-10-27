<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the scheduled outbox repair (eis:prepare-pending) look only at recently created documents instead of
 * scanning every document ever issued. Index only: no data changes.
 */
return new class extends Migration
{
    private const TABLES = ['transaction_receipts', 'business_invoices'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'created_at') && ! Schema::hasIndex($table, ['created_at'])) {
                Schema::table($table, fn (Blueprint $t) => $t->index('created_at'));
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, ['created_at'])) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex(['created_at']));
            }
        }
    }
};
