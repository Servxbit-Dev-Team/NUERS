<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexed form of the document-number identity used by IssuedDocumentLedger.
 *
 * The ledger compares UPPER(TRIM(number)). A virtual generated column holds exactly that value, so identity
 * lookups can use an index instead of rebuilding the whole ledger. It is derived by the database: no writer
 * sets it, no existing row changes, and blank numbers yield NULL (the ledger falls back to the row id for them).
 */
return new class extends Migration
{
    private const TABLES = ['transaction_receipts' => 'receipt_number', 'business_invoices' => 'invoice_number'];

    public function up(): void
    {
        foreach (self::TABLES as $table => $number) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'document_number_key')) {
                continue;
            }
            Schema::table($table, fn (Blueprint $t) => $t->string('document_number_key')->nullable()->virtualAs("UPPER(TRIM(NULLIF($number, '')))")->index());
        }
    }

    public function down(): void
    {
        // The column is derived and holds no data of its own, so it is safe to remove.
        foreach (array_keys(self::TABLES) as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'document_number_key')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropIndex(['document_number_key']);
                    $t->dropColumn('document_number_key');
                });
            }
        }
    }
};
