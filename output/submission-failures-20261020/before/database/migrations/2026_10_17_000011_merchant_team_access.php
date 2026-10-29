<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_team_roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80)->index();
            $t->string('name', 80);
            $t->string('description', 300);
            $t->string('color', 20);
            $t->json('permissions');
            $t->unsignedInteger('revision')->default(1);
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
            $t->unique(['merchant_id', 'name']);
        });
        Schema::create('merchant_team_members', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80)->index();
            $t->string('user_id', 80)->nullable()->unique();
            $t->uuid('role_id');
            $t->text('profile_encrypted');
            $t->char('email_hash', 64);
            $t->string('scope', 20);
            $t->json('branch_ids');
            $t->string('terminal_scope', 20);
            $t->json('terminal_ids');
            $t->string('status', 20);
            $t->unsignedInteger('revision')->default(1);
            $t->char('invite_hash', 64)->nullable()->unique();
            $t->dateTime('expires_at')->nullable();
            $t->dateTime('accepted_at')->nullable();
            $t->string('invited_by', 80);
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
            $t->unique(['merchant_id', 'email_hash']);
        });
        Schema::create('merchant_team_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('merchant_id', 80)->index();
            $t->string('subject_id', 80);
            $t->string('actor_id', 80);
            $t->string('action', 50);
            $t->text('details_encrypted');
            $t->dateTime('created_at');
            $t->index(['merchant_id', 'created_at']);
        });
        foreach (['business_invoices', 'transaction_receipts'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->uuid('issuer_terminal_id')->nullable()->index());
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve team grants and security audit history; use a reviewed forward migration.');
    }
};
