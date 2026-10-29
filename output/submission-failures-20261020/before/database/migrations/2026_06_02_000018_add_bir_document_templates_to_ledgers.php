<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('merchant_transactions', 'document_type')) {
                $table->string('document_type')->nullable()->index();
            }

            if (! Schema::hasColumn('merchant_transactions', 'bir_template_code')) {
                $table->string('bir_template_code', 8)->nullable()->index();
            }
        });

        Schema::table('transaction_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('transaction_receipts', 'document_type')) {
                $table->string('document_type')->nullable()->index();
            }

            if (! Schema::hasColumn('transaction_receipts', 'bir_template_code')) {
                $table->string('bir_template_code', 8)->nullable()->index();
            }
        });

        Schema::table('business_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('business_invoices', 'bir_template_code')) {
                $table->string('bir_template_code', 8)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('business_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('business_invoices', 'bir_template_code')) {
                $table->dropColumn('bir_template_code');
            }
        });

        Schema::table('transaction_receipts', function (Blueprint $table) {
            foreach (['bir_template_code', 'document_type'] as $column) {
                if (Schema::hasColumn('transaction_receipts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('merchant_transactions', function (Blueprint $table) {
            foreach (['bir_template_code', 'document_type'] as $column) {
                if (Schema::hasColumn('merchant_transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
