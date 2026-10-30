<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('taxpayer_authorizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('request_id')->unique();
            $t->char('request_hash', 64);
            $t->uuid('merchant_id')->index();
            $t->string('kind', 3);
            $t->string('reference', 120);
            $t->string('environment', 20);
            $t->text('scope');
            $t->date('issued_at');
            $t->date('valid_until')->nullable();
            $t->string('status', 20)->default('pending');
            $t->text('evidence');
            $t->text('reason');
            $t->unsignedInteger('revision')->default(1);
            $t->string('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['kind', 'reference']);
        });
        Schema::create('taxpayer_devices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('request_id')->unique();
            $t->char('request_hash', 64);
            $t->uuid('authorization_id')->index();
            $t->string('name', 120);
            $t->string('serial_number', 120)->unique();
            $t->string('branch', 120);
            $t->string('device_type', 30);
            $t->string('status', 20)->default('registered');
            $t->text('reason');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        Schema::create('taxpayer_connection_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('device_id')->index();
            $t->uuid('request_id')->unique();
            $t->string('status', 20);
            $t->text('evidence');
            $t->text('reason');
            $t->string('recorded_by');
            $t->dateTime('observed_at');
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxpayer_connection_events');
        Schema::dropIfExists('taxpayer_devices');
        Schema::dropIfExists('taxpayer_authorizations');
    }
};
