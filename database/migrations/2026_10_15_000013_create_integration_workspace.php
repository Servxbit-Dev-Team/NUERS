<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('request_id')->unique();
            $t->string('name', 120);
            $t->string('provider', 30);
            $t->uuid('merchant_id')->nullable()->index();
            $t->string('environment', 20);
            $t->string('base_url', 500);
            $t->string('client_id', 255)->nullable();
            $t->text('encrypted_secret')->nullable();
            $t->string('secret_hint', 12)->nullable();
            $t->timestamp('secret_updated_at')->nullable();
            $t->string('api_version', 50);
            $t->unsignedInteger('timeout_seconds')->default(30);
            $t->unsignedInteger('max_retries')->default(3);
            $t->string('status', 20)->default('draft');
            $t->text('evidence');
            $t->text('reason');
            $t->string('certificate_fingerprint', 95)->nullable();
            $t->timestamp('certificate_expires_at')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        Schema::create('integration_endpoints', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('connection_id')->index();
            $t->string('name', 120);
            $t->string('method', 10);
            $t->string('path', 255);
            $t->text('description');
            $t->boolean('enabled')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->unique(['connection_id', 'method', 'path']);
        });
        Schema::create('integration_webhooks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('connection_id')->index();
            $t->string('url', 500);
            $t->json('events');
            $t->text('encrypted_secret');
            $t->string('status', 20)->default('disabled');
            $t->text('evidence');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_webhooks');
        Schema::dropIfExists('integration_endpoints');
        Schema::dropIfExists('integration_connections');
    }
};
