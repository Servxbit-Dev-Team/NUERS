<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('webhook_id')->index();
            $t->string('merchant_id', 80)->index();
            $t->string('event_key', 64)->unique();
            $t->string('event_type', 80);
            $t->string('subject_id', 80);
            $t->text('encrypted_body');
            $t->string('body_hash', 64);
            $t->string('state', 30)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('available_at')->nullable();
            $t->uuid('lease_token')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->unsignedInteger('last_http_status')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
            $t->index(['state', 'available_at'], 'webhook_claim');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve webhook delivery evidence.');
    }
};
