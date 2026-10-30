<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('verification_upload_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('merchant_id')->nullable()->index();
            $t->uuid('challenge_id')->nullable()->index();
            $t->uuid('actor_id')->nullable();
            $t->string('kind', 64);
            $t->text('filename_encrypted');
            $t->unsignedInteger('bytes');
            $t->unsignedSmallInteger('next_index')->default(0);
            $t->uuid('document_id')->nullable();
            $t->dateTime('expires_at')->index();
            $t->dateTime('created_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('verification_upload_sessions');
    }
};
