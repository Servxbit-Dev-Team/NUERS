<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('merchant_customers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->char('merchant_id', 36);
            $t->string('code', 24)->unique();
            $t->string('type', 20);
            $t->string('status', 20)->default('active');
            $t->string('group_name', 80)->default('Standard');
            $t->string('branch_code', 100)->nullable();
            $t->longText('profile_encrypted');
            $t->string('identity_hash', 64);
            $t->boolean('has_tin')->default(false);
            $t->uuid('request_id');
            $t->string('request_hash', 64);
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('details_reviewed_at')->nullable();
            $t->string('reviewed_by', 64)->nullable();
            $t->text('review_reference_encrypted')->nullable();
            $t->timestamps();
            $t->unique(['merchant_id', 'identity_hash']);
            $t->unique(['merchant_id', 'request_id']);
            $t->index(['merchant_id', 'status', 'created_at']);
            $t->index(['merchant_id', 'type', 'created_at']);
        });
        Schema::create('merchant_customer_search', function (Blueprint $t) {
            $t->char('merchant_id', 36);
            $t->uuid('customer_id');
            $t->string('term_hash', 64);
            $t->unique(['customer_id', 'term_hash']);
            $t->index(['merchant_id', 'term_hash']);
        });
        Schema::create('merchant_customer_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->char('merchant_id', 36);
            $t->uuid('customer_id');
            $t->string('document_source', 12);
            $t->string('document_id', 120);
            $t->string('actor_id', 64);
            $t->text('reason_encrypted');
            $t->timestamps();
            $t->unique(['merchant_id', 'document_source', 'document_id'], 'customer_document_once');
            $t->index(['merchant_id', 'customer_id']);
        });
        Schema::create('merchant_customer_history', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->char('merchant_id', 36);
            $t->uuid('customer_id');
            $t->unsignedInteger('revision');
            $t->string('actor_id', 64);
            $t->longText('change_encrypted');
            $t->timestamps();
            $t->unique(['customer_id', 'revision']);
        });
        Schema::create('merchant_customer_imports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->char('merchant_id', 36);
            $t->uuid('request_id');
            $t->string('request_hash', 64);
            $t->unsignedInteger('created_count');
            $t->timestamps();
            $t->unique(['merchant_id', 'request_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve customer relationships and audit evidence. Use a reviewed forward migration.');
    }
};
