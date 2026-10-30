<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('merchant_branches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80)->index();
            $t->string('code', 30);
            $t->string('bir_branch_code', 5)->nullable();
            $t->string('name', 160);
            $t->text('address');
            $t->string('city', 100);
            $t->string('region', 100);
            $t->string('rdo_code', 10);
            $t->string('branch_type', 20);
            $t->string('status', 20);
            $t->string('review_status', 30)->default('draft');
            $t->text('contact_encrypted');
            $t->unsignedInteger('revision')->default(1);
            $t->uuid('request_id');
            $t->char('request_hash', 64);
            $t->timestamp('requested_at')->nullable();
            $t->string('reviewed_by', 80)->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_reference')->nullable();
            $t->timestamps();
            $t->unique(['merchant_id', 'bir_branch_code']);
            $t->unique(['merchant_id', 'request_id']);
            $t->index(['merchant_id', 'status', 'city']);
        });
        Schema::create('merchant_terminals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80)->index();
            $t->uuid('branch_id')->index();
            $t->string('code', 30);
            $t->string('identifier', 100);
            $t->string('name', 160);
            $t->string('pos_type', 30);
            $t->string('status', 20);
            $t->string('api_key_id', 80)->nullable();
            $t->uuid('taxpayer_device_id')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->uuid('request_id');
            $t->char('request_hash', 64);
            $t->timestamps();
            $t->unique(['merchant_id', 'identifier']);
            $t->unique(['merchant_id', 'request_id']);
            $t->index(['merchant_id', 'branch_id', 'status']);
        });
        Schema::create('merchant_branch_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80)->index();
            $t->uuid('branch_id')->index();
            $t->string('kind', 40);
            $t->text('filename_encrypted');
            $t->string('mime', 80);
            $t->unsignedInteger('bytes');
            $t->char('sha256', 64);
            $t->string('private_path', 200);
            $t->string('actor_id', 80);
            $t->dateTime('created_at');
            $t->unique(['branch_id', 'kind', 'sha256']);
        });
        Schema::create('merchant_branch_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80)->index();
            $t->uuid('branch_id')->nullable()->index();
            $t->uuid('terminal_id')->nullable();
            $t->string('action', 60);
            $t->string('actor_id', 80);
            $t->longText('details_encrypted');
            $t->dateTime('created_at');
            $t->index(['merchant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve branch identity, private evidence and audit history.');
    }
};
