<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_review_notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80)->index();
            $t->string('actor_id', 64);
            $t->uuid('request_id');
            $t->string('kind', 32);
            $t->char('decision_fingerprint', 64);
            $t->char('request_hash', 64);
            $t->uuid('outbox_id')->unique();
            $t->text('encrypted_snapshot');
            $t->dateTime('created_at');
            $t->unique(['merchant_id', 'request_id']);
            $t->index(['merchant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Review notification records are retained. Use a reviewed forward migration.');
    }
};
