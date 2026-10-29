<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_business_profiles', function (Blueprint $t) {
            $t->string('merchant_id', 80)->primary();
            $t->text('encrypted_details');
            $t->unsignedInteger('revision')->default(1);
            $t->char('submitted_fingerprint', 64)->nullable();
            $t->dateTime('review_requested_at')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Business profile review history is retained. Use a reviewed forward migration.');
    }
};
