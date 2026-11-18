<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('website_subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedInteger('fee_centavos');
            $table->unsignedBigInteger('minimum_transaction_centavos')->default(50000);
            $table->unsignedInteger('storage_mb')->nullable();
            $table->unsignedSmallInteger('retention_years')->nullable();
            $table->text('description');
            $table->string('badge')->nullable();
            $table->boolean('featured')->default(false);
            $table->boolean('published')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        DB::table('website_subscription_plans')->insert([
            ['slug' => 'subscription-1', 'name' => 'Subscription 1', 'fee_centavos' => 200, 'minimum_transaction_centavos' => 50000, 'storage_mb' => 500, 'retention_years' => null, 'description' => 'A simple starting point for businesses that need digital invoices, receipts and essential document storage.', 'badge' => null, 'featured' => false, 'published' => true, 'position' => 1, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'subscription-2', 'name' => 'Subscription 2', 'fee_centavos' => 300, 'minimum_transaction_centavos' => 50000, 'storage_mb' => null, 'retention_years' => 5, 'description' => 'Room to grow, with unlimited document storage and five-year record retention under this plan.', 'badge' => 'More room to grow', 'featured' => true, 'published' => true, 'position' => 2, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
    public function down(): void
    {
        Schema::dropIfExists('website_subscription_plans');
    }
};
