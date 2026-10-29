<?php

use App\Services\MerchantCustomers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_customers', function (Blueprint $t) {
            foreach (['name_hash', 'tin_hash', 'email_hash', 'phone_hash'] as $column) {
                $t->string($column, 64)->nullable();
                $t->index(['merchant_id', $column]);
            }
        });
        Schema::table('business_invoices', fn (Blueprint $t) => $t->string('buyer_phone', 30)->nullable());
        Schema::create('invoice_create_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->char('merchant_id', 36);
            $t->uuid('request_id');
            $t->string('request_hash', 64);
            $t->uuid('invoice_id');
            $t->uuid('customer_id');
            $t->boolean('customer_created');
            $t->timestamps();
            $t->unique(['merchant_id', 'request_id']);
        });
        DB::table('merchant_customers')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $p = json_decode(Crypt::decryptString($row->profile_encrypted), true, flags: JSON_THROW_ON_ERROR);
                DB::table('merchant_customers')->where('id', $row->id)->update(app(MerchantCustomers::class)->matchHashes($row->merchant_id, $p));
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Customer relationship indexes must be retained. Use a reviewed forward migration.');
    }
};
