<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $t) {
            $t->unsignedInteger('registry_revision')->default(1);
            $t->json('registry_details')->nullable();
        });
        Schema::create('business_registry_lock', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
        });
        DB::table('business_registry_lock')->insert(['id' => 1]);
        Schema::create('business_registry_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('actor_id');
            $t->string('purpose', 30);
            $t->uuid('merchant_id')->nullable()->index();
            $t->char('request_hash', 64);
            $t->char('token_hash', 64)->nullable()->unique();
            $t->longText('payload');
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_registry_requests');
        Schema::dropIfExists('business_registry_lock');
        Schema::table('merchants', fn (Blueprint $t) => $t->dropColumn(['registry_revision', 'registry_details']));
    }
};
