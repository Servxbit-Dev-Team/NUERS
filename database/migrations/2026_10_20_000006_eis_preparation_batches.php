<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('eis_preparation_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('actor_id', 80);
            $t->string('merchant_id', 80)->index();
            $t->string('request_hash', 64);
            $t->string('status', 30)->default('pending')->index();
            $t->unsignedInteger('document_count');
            $t->text('reason');
            $t->json('filters');
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('eis_preparation_batch_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('batch_id')->index();
            $t->string('merchant_id', 80);
            $t->string('document_source', 20);
            $t->string('document_id', 80);
            $t->string('document_number', 255)->nullable();
            $t->string('expected_hash', 64);
            $t->uuid('outbox_id')->nullable();
            $t->string('status', 30)->default('pending')->index();
            $t->text('message')->nullable();
            $t->timestamps();
            $t->unique(['batch_id', 'document_source', 'document_id'], 'eis_batch_document_unique');
        });
    }
    public function down(): void
    {
        throw new RuntimeException('Preserve batch audit evidence; use a reviewed forward migration.');
    }
};
