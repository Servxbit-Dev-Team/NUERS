<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_mail_settings', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->text('encrypted_settings');
            $t->unsignedInteger('revision')->default(1);
            $t->char('connection_hash', 64);
            $t->char('tested_hash', 64)->nullable();
            $t->timestamp('tested_at')->nullable();
            $t->string('updated_by', 80);
            $t->timestamps();
        });
        Schema::create('platform_configuration_changes', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('module', 80); $t->string('action', 80);
            $t->unsignedInteger('revision'); $t->string('actor_id', 80); $t->json('changed_fields');
            $t->timestamp('created_at')->index();
        });
        Schema::create('document_email_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('request_id'); $t->string('merchant_id', 80)->index();
            $t->string('document_source', 20); $t->string('document_id', 80); $t->string('actor_id', 80);
            $t->text('encrypted_recipient'); $t->char('request_hash', 64); $t->char('pdf_hash', 64)->nullable();
            $t->string('state', 30); $t->string('purpose', 30)->default('customer_copy');
            $t->string('error_code', 80)->nullable(); $t->timestamps();
            $t->unique(['merchant_id', 'request_id']);
        });
    }
    public function down(): void { throw new RuntimeException('Preserve mail configuration and delivery audit history.'); }
};
