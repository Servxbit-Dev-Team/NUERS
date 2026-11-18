<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('registration_email_verifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('email')->index();
            $t->string('code_hash');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('expires_at');
            $t->timestamp('verified_at')->nullable();
            $t->char('proof_hash', 64)->nullable();
            $t->timestamp('consumed_at')->nullable();
            $t->boolean('development_delivery')->default(false);
            $t->timestamps();
        });
        Schema::create('business_registrations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->unique()->constrained('users');
            $t->uuid('merchant_id')->unique();
            $t->char('business_tin', 12)->unique();
            $t->string('contact_name');
            $t->string('contact_position');
            $t->string('mobile', 20);
            $t->string('status')->default('pending_review')->index();
            $t->foreignId('subscription_plan_id')->nullable()->constrained('website_subscription_plans');
            $t->json('plan_snapshot')->nullable();
            $t->string('terms_version');
            $t->string('privacy_version');
            $t->timestamp('consented_at');
            $t->boolean('email_verified')->default(false);
            $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('business_registrations');
        Schema::dropIfExists('registration_email_verifications');
    }
};
