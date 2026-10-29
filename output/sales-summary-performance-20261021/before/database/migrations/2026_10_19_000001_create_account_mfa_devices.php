<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_mfa_devices', function (Blueprint $t) {
            $t->string('id', 64)->primary();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('name', 80);
            $t->text('encrypted_secret')->nullable();
            $t->string('state', 16);
            $t->boolean('is_primary')->default(false);
            $t->unsignedBigInteger('last_counter')->nullable();
            $t->string('setup_binding', 64)->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_mfa_devices');
    }
};
