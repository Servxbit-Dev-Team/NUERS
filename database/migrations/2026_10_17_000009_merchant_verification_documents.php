<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('merchant_verification_documents', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('document_id')->unique();
            $t->string('merchant_id', 80)->nullable()->index();
            $t->uuid('challenge_id')->nullable()->index();
            $t->string('kind', 40);
            $t->string('status', 30)->default('pending');
            $t->text('filename_encrypted');
            $t->text('private_path');
            $t->char('sha256', 64);
            $t->unsignedInteger('bytes');
            $t->string('mime', 80);
            $t->string('uploaded_by', 80)->nullable();
            $t->string('reviewed_by', 80)->nullable();
            $t->text('review_encrypted')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('claimed_at')->nullable();
            $t->index(['merchant_id', 'kind', 'id']);
        });
        Schema::table('business_registrations', fn (Blueprint $t) => $t->boolean('documents_required')->default(false));
    }

    public function down(): void
    {
        throw new RuntimeException('Verification evidence is retained; use a reviewed forward migration.');
    }
};
