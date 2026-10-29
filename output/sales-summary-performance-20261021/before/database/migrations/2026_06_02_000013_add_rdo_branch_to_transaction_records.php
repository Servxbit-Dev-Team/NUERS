<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('merchant_transactions', 'rdo_code')) {
                $table->string('rdo_code', 20)->nullable()->after('branch')->index();
            }

            if (! Schema::hasColumn('merchant_transactions', 'rdo_name')) {
                $table->string('rdo_name')->nullable()->after('rdo_code');
            }
        });

        Schema::table('transaction_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('transaction_receipts', 'rdo_code')) {
                $table->string('rdo_code', 20)->nullable()->after('merchant_tin')->index();
            }

            if (! Schema::hasColumn('transaction_receipts', 'rdo_name')) {
                $table->string('rdo_name')->nullable()->after('rdo_code');
            }
        });

        $this->backfillRdoFields('merchant_transactions');
        $this->backfillRdoFields('transaction_receipts');
    }

    public function down(): void
    {
        Schema::table('transaction_receipts', function (Blueprint $table) {
            if (Schema::hasColumn('transaction_receipts', 'rdo_name')) {
                $table->dropColumn('rdo_name');
            }

            if (Schema::hasColumn('transaction_receipts', 'rdo_code')) {
                $table->dropColumn('rdo_code');
            }
        });

        Schema::table('merchant_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('merchant_transactions', 'rdo_name')) {
                $table->dropColumn('rdo_name');
            }

            if (Schema::hasColumn('merchant_transactions', 'rdo_code')) {
                $table->dropColumn('rdo_code');
            }
        });
    }

    private function backfillRdoFields(string $table): void
    {
        $rows = DB::table($table)
            ->whereNotNull('merchant_id')
            ->where(function ($query): void {
                $query
                    ->whereNull('rdo_code')
                    ->orWhere('rdo_code', '')
                    ->orWhereNull('rdo_name')
                    ->orWhere('rdo_name', '');
            })
            ->get(['id', 'merchant_id', 'rdo_code', 'rdo_name']);

        if ($rows->isEmpty()) {
            return;
        }

        $merchants = DB::table('merchants')
            ->whereIn('id', $rows->pluck('merchant_id')->filter()->unique()->values())
            ->get(['id', 'rdo_code', 'rdo_name'])
            ->keyBy('id');

        foreach ($rows as $row) {
            $merchant = $merchants->get($row->merchant_id);

            if (! $merchant) {
                continue;
            }

            $updates = [];

            if ($this->isBlank($row->rdo_code) && ! $this->isBlank($merchant->rdo_code)) {
                $updates['rdo_code'] = $merchant->rdo_code;
            }

            if ($this->isBlank($row->rdo_name) && ! $this->isBlank($merchant->rdo_name)) {
                $updates['rdo_name'] = $merchant->rdo_name;
            }

            if ($updates !== []) {
                DB::table($table)->where('id', $row->id)->update($updates);
            }
        }
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }
};
