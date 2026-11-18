<?php

use App\Services\Infrastructure\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require '/srv/nuers/current/vendor/autoload.php';
$app = require '/srv/nuers/current/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli') { exit(1); }
$pins = config('infrastructure.node_pins');
if (count($pins) !== 9) { throw new RuntimeException('Expected nine reviewed AWS identities.'); }
DB::transaction(function () use ($pins) {
    DB::table('infra_state')->where('id', 1)->lockForUpdate()->first();
    foreach ($pins as $code => $pin) {
        $node = DB::table('infra_nodes')->where('code', $code)->first();
        if ($node && $node->enrollment === 'enrolled') {
            if ($node->fingerprint !== $pin['fingerprint']) { throw new RuntimeException('Enrolled identity mismatch.'); }
            continue;
        }
        $fields = array_intersect_key($pin, array_flip(['tier', 'label', 'ha_group', 'environment', 'provider', 'resource_type', 'provider_reference', 'region', 'network_zone', 'site', 'planned_specs']));
        $id = $node?->id ?? (string) Str::uuid();
        DB::table('infra_nodes')->updateOrInsert(['code' => $code], $fields + ['id' => $id, 'enrollment' => 'pending', 'updated_at' => now('UTC'), 'created_at' => $node?->created_at ?? now('UTC')]);
        app(Audit::class)->record('node.aws_inventory_registered', $id, 'infrastructure-operator', ['code' => $code, 'resource_reference' => $pin['provider_reference']]);
    }
    foreach (['LB-02', 'DB-02'] as $code) {
        $node = DB::table('infra_nodes')->where('code', $code)->first();
        if ($node && $node->enrollment === 'planned' && ! $node->fingerprint && ! DB::table('infra_actions')->where('node_id', $node->id)->exists()) {
            DB::table('infra_nodes')->where('id', $node->id)->update(['enrollment' => 'retired', 'updated_at' => now('UTC')]);
            app(Audit::class)->record('node.placeholder_retired', $node->id, 'infrastructure-operator', ['code' => $code, 'reason' => 'Managed ALB and RDS Multi-AZ replace planned individual machines.']);
        }
    }
});
echo "Reviewed AWS inventory enrolled; historical placeholder records retained.\n";
