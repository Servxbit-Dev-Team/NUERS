<?php

use App\Services\Infrastructure\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('infra_state', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('audit_head', 64)->default(str_repeat('0', 64));
            $t->timestamp('last_sync_at')->nullable();
            $t->string('sync_state')->default('not_configured');
        });
        DB::table('infra_state')->insert(['id' => 1]);
        Schema::create('infra_nodes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 30)->unique();
            $t->string('tier', 30);
            $t->string('label', 120);
            $t->string('ha_group', 50)->index();
            $t->string('environment', 20)->default('production')->index();
            $t->string('site', 100)->default('Not assigned');
            $t->string('planned_specs', 250)->nullable();
            $t->string('enrollment', 30)->default('planned');
            $t->string('fingerprint', 64)->nullable();
            $t->json('telemetry')->nullable();
            $t->timestamp('observed_at')->nullable();
            $t->timestamp('maintenance_start')->nullable();
            $t->timestamp('maintenance_end')->nullable();
            $t->string('maintenance_reason', 500)->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        // Planned inventory only: no IP addresses, agent identities or health assertions.
        foreach (Catalog::inventory() as [$code, $tier, $label, $group, $specs]) {
            DB::table('infra_nodes')->insert(['id' => (string) Str::uuid(), 'code' => $code, 'tier' => $tier, 'label' => $label, 'ha_group' => $group, 'planned_specs' => $specs, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        }
        Schema::create('infra_samples', function (Blueprint $t) {
            $t->id();
            $t->uuid('node_id')->index();
            $t->timestamp('observed_at');
            $t->json('metrics');
            $t->unique(['node_id', 'observed_at']);
        });
        Schema::create('infra_proofs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('actor_id', 80);
            $t->string('binding', 64);
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
        });
        Schema::create('infra_actions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('node_id')->index();
            $t->string('actor_id', 80);
            $t->string('session_binding', 64);
            $t->uuid('idempotency_key');
            $t->string('request_hash', 64);
            $t->string('action', 60);
            $t->string('risk', 20);
            $t->string('state', 30)->index();
            $t->string('reason', 500);
            $t->json('parameters');
            $t->json('preflight');
            $t->json('policy');
            $t->timestamp('expires_at');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('dispatched_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->json('result')->nullable();
            $t->timestamps();
            $t->unique(['actor_id', 'idempotency_key']);
        });
        Schema::create('infra_approvals', function (Blueprint $t) {
            $t->id();
            $t->uuid('action_id')->index();
            $t->string('actor_id', 80);
            $t->string('session_binding', 64);
            $t->string('decision', 15);
            $t->string('note', 500);
            $t->timestamp('created_at');
            $t->unique(['action_id', 'actor_id']);
        });
        Schema::create('infra_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('subject_id')->nullable()->index();
            $t->string('actor_id', 80);
            $t->string('event', 100);
            $t->json('details');
            $t->string('previous_hash', 64);
            $t->string('entry_hash', 64);
            $t->string('occurred_at', 40);
        });
    }

    public function down(): void
    {
        foreach (['infra_events', 'infra_approvals', 'infra_actions', 'infra_proofs', 'infra_samples', 'infra_nodes', 'infra_state'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
