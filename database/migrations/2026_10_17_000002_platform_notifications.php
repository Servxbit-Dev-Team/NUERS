<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_outbox', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('dedupe_key', 64)->unique();
            $t->string('event', 100);
            $t->string('category', 30);
            $t->string('user_id', 64)->nullable()->index();
            $t->string('merchant_id', 64)->nullable()->index();
            $t->text('encrypted_payload')->nullable();
            $t->string('state', 24)->default('queued');
            $t->unsignedTinyInteger('priority')->default(20);
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->uuid('lease_token')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->dateTime('available_at');
            $t->dateTime('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->string('error_code', 60)->nullable();
            $t->timestamps();
            $t->index(['state', 'available_at', 'priority']);
            $t->index(['state', 'lease_until']);
        });
        Schema::table('document_email_deliveries', function (Blueprint $t) {
            $t->uuid('outbox_id')->nullable()->index();
        });
        Schema::create('notification_preferences', function (Blueprint $t) {
            $t->string('user_id', 64)->primary();
            $t->boolean('workflow_email')->default(true);
            $t->boolean('support_email')->default(true);
            $t->boolean('operations_email')->default(true);
            $t->timestamps();
        });
        Schema::create('notification_runtime', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->dateTime('activated_at');
            $t->timestamp('worker_seen_at')->nullable();
        });
        DB::table('notification_runtime')->insert(['id' => 1, 'activated_at' => now()]);
        Schema::create('account_email_verifications', function (Blueprint $t) {
            $t->string('user_id', 64)->primary();
            $t->string('token_hash', 64);
            $t->dateTime('expires_at');
            $t->dateTime('created_at');
        });
        Schema::create('account_password_resets', function (Blueprint $t) {
            $t->string('user_id', 64)->primary();
            $t->string('token_hash', 64);
            $t->dateTime('expires_at');
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve notification and security delivery evidence; use a reviewed forward migration.');
    }
};
