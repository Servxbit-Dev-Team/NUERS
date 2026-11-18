<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('infra_nodes', function (Blueprint $t) {
            $t->string('provider', 30)->default('unassigned')->index();
            $t->string('resource_type', 40)->default('virtual_machine');
            $t->string('provider_reference', 200)->nullable();
            $t->string('region', 80)->nullable();
            $t->string('network_zone', 80)->nullable();
        });
        Schema::create('infra_expansion_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('actor_id', 80);
            $t->uuid('idempotency_key');
            $t->string('request_hash', 64);
            $t->string('provider', 30);
            $t->string('resource_type', 40);
            $t->string('tier', 30);
            $t->string('environment', 20);
            $t->string('region', 80);
            $t->string('network_zone', 80);
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('vcpu')->nullable();
            $t->unsignedInteger('memory_gib')->nullable();
            $t->unsignedInteger('storage_gib')->nullable();
            $t->unsignedBigInteger('estimated_monthly_php_centavos')->nullable();
            $t->string('reason', 500);
            $t->string('state', 30)->default('awaiting_review')->index();
            $t->timestamps();
            $t->unique(['actor_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_expansion_requests');
        Schema::table('infra_nodes', fn (Blueprint $t) => $t->dropColumn([
            'provider', 'resource_type', 'provider_reference', 'region', 'network_zone',
        ]));
    }
};
