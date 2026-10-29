<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('pti_applications')) Schema::create('pti_applications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('request_id')->unique();
            $t->char('request_hash',64);
            $t->uuid('merchant_id');
            $t->string('created_by',64);
            $t->string('status',30)->default('draft');
            $t->unsignedInteger('revision')->default(1);
            $t->longText('payload_encrypted');
            $t->uuid('authorization_id')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamps();
            $t->index(['merchant_id','created_at','id'],'pti_app_merchant_date');
            $t->index(['status','created_at','id'],'pti_app_status_date');
        });
        if (!Schema::hasTable('pti_application_documents')) Schema::create('pti_application_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('application_id');
            $t->string('kind',40);
            $t->text('filename_encrypted');
            $t->string('private_path');
            $t->string('mime',64);
            $t->unsignedInteger('bytes');
            $t->char('sha256',64);
            $t->string('uploaded_by',64);
            $t->timestamp('created_at');
            $t->index(['application_id','kind','created_at'],'pti_doc_application');
        });
        if (!Schema::hasTable('pti_application_events')) Schema::create('pti_application_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('application_id');
            $t->string('actor_id',64);
            $t->string('event',40);
            $t->unsignedInteger('revision');
            $t->longText('details_encrypted');
            $t->timestamp('created_at');
            $t->index(['application_id','id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pti_application_events');
        Schema::dropIfExists('pti_application_documents');
        Schema::dropIfExists('pti_applications');
    }
};
