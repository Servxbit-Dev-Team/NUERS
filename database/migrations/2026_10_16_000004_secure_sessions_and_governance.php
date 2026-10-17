<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('api_token_hash', 64)->nullable()->unique();
            $t->timestamp('api_expires_at')->nullable();
            $t->timestamp('api_authenticated_at')->nullable();
            $t->timestamp('api_mfa_verified_at')->nullable();
            $t->text('mfa_secret')->nullable();
            $t->text('mfa_pending_secret')->nullable();
            $t->timestamp('mfa_pending_at')->nullable();
            $t->unsignedBigInteger('mfa_last_counter')->nullable();
            $t->json('mfa_recovery_hashes')->nullable();
        });
        // Reauthentication is required for legacy unbounded/plaintext sessions; passwords/roles/financial rows stay intact.
        DB::table('users')->whereNotNull('api_token')->update(['api_token' => null]);
        Schema::create('invoice_lifecycle_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('document_source', 20);
            $t->string('document_id', 80)->index();
            $t->string('merchant_id', 80)->index();
            $t->string('event', 60);
            $t->string('actor');
            $t->text('reason')->nullable();
            $t->json('details')->nullable();
            $t->timestamp('occurred_at');
            $t->uuid('request_id')->nullable()->unique();
        });
        Schema::create('document_review_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80)->index();
            $t->string('document_source', 20);
            $t->string('document_id', 80)->index();
            $t->string('kind', 30);
            $t->string('status', 30)->default('pending');
            $t->text('reason');
            $t->string('requested_by');
            $t->string('reviewed_by')->nullable();
            $t->text('evidence_reference')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->uuid('request_id')->unique();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward migration; preserve security and document review evidence.');
    }
};
