<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bcdr_policy', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->unsignedInteger('revision')->default(0);
            $t->json('payload');
            $t->string('approved_by', 64)->nullable();
            $t->text('evidence_reference')->nullable();
            $t->timestamps();
        });
        DB::table('bcdr_policy')->insert(['id' => 1, 'revision' => 0, 'payload' => json_encode([
            'database_minutes' => 60, 'objects_minutes' => 360, 'logs_minutes' => 15, 'configuration_minutes' => 1440,
            'operational_days' => 90, 'rpo_minutes' => 15, 'rto_minutes' => 240, 'drill_days' => 90,
            'observation_minutes' => 60, 'primary_site' => 'Primary site', 'dr_site' => 'Recovery site',
            'runbook_reference' => '', 'retention_reference' => '', 'key_custody_reference' => '',
        ]), 'created_at' => now(), 'updated_at' => now()]);
        foreach (['bcdr_requests', 'bcdr_evidence'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('request_id');
                $t->string('actor_id', 64);
                $t->string('kind', 30);
                $t->json('payload');
                $t->string('request_hash', 64);
                $t->string('status', 30)->default('pending_review');
                $t->text('evidence_reference')->nullable();
                $t->string('reviewed_by', 64)->nullable();
                $t->text('review_note')->nullable();
                $t->unsignedInteger('revision')->default(1);
                $t->timestamp('reviewed_at')->nullable();
                $t->timestamps();
                $t->unique(['actor_id', 'request_id']);
                $t->index(['kind', 'created_at']);
                $t->index(['status', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Backup and recovery evidence must be preserved. Use a reviewed forward migration.');
    }
};
