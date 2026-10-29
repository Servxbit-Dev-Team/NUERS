<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Infrastructure\ActionRequest;
use App\Models\User;
use App\Services\ApiAccess;
use App\Services\Infrastructure\ActionWorkflow;
use App\Services\Infrastructure\Audit;
use App\Services\Infrastructure\Catalog;
use App\Services\Infrastructure\ControllerGateway;
use App\Services\Infrastructure\Preflight;
use App\Services\Infrastructure\Telemetry;
use App\Services\MfaDevices;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ServerManagementController
{
    private function actor(Request $r): User
    {
        return app(ApiAccess::class)->require($r, ['super_admin']);
    }

    private function json(array $v, int $code = 200)
    {
        return response()->json($v, $code)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $r)
    {
        $actor = $this->actor($r);
        $nodes = DB::table('infra_nodes')->orderByRaw("CASE tier WHEN 'load_balancer' THEN 1 WHEN 'web' THEN 2 WHEN 'database' THEN 3 ELSE 4 END")->orderBy('code')->get()->map(fn ($n) => app(Telemetry::class)->present($n));
        $fresh = $nodes->filter(fn ($n) => $n['fresh'] && ($n['telemetry']['reachable'] ?? false));
        $cpu = $fresh->filter(fn ($n) => isset($n['telemetry']['cpu_percent']));
        $ram = $fresh->filter(fn ($n) => isset($n['telemetry']['memory_percent']));
        $disk = $fresh->filter(fn ($n) => isset($n['telemetry']['disk_used_bytes'], $n['telemetry']['disk_total_bytes']));

        return $this->json(['nodes' => $nodes, 'actor_id' => (string) $actor->id, 'actions' => Catalog::actions(),
            'summary' => ['total' => $nodes->count(), 'enrolled' => $nodes->where('enrollment', 'enrolled')->count(), 'online' => $nodes->where('status', 'online')->count(), 'offline' => $nodes->where('status', 'offline')->count(), 'unknown' => $nodes->whereIn('status', ['not_configured', 'stale'])->count(), 'degraded' => $nodes->where('status', 'degraded')->count(), 'maintenance' => $nodes->where('status', 'maintenance')->count(), 'cpu' => $cpu->count() ? round($cpu->avg('telemetry.cpu_percent'), 1) : null, 'memory' => $ram->count() ? round($ram->avg('telemetry.memory_percent'), 1) : null, 'disk_used' => $disk->count() ? $disk->sum('telemetry.disk_used_bytes') : null, 'disk_total' => $disk->count() ? $disk->sum('telemetry.disk_total_bytes') : null, 'cpu_samples' => $cpu->count(), 'memory_samples' => $ram->count(), 'disk_samples' => $disk->count()],
            'controller' => ['configured' => app(ControllerGateway::class)->ready(), 'execution_enabled' => (bool) config('infrastructure.execution_enabled'), 'last_sync_at' => DB::table('infra_state')->value('last_sync_at'), 'sync_state' => DB::table('infra_state')->value('sync_state'), 'freshness_seconds' => config('infrastructure.freshness_seconds'), 'protocol' => Catalog::VERSION], 'generated_at' => now('UTC')->toIso8601String()]);
    }

    public function detail(Request $r, string $id)
    {
        $this->actor($r);
        $r->validate(['range' => 'nullable|in:1h,6h,24h,7d']);
        $n = DB::table('infra_nodes')->where('id', $id)->first();
        abort_unless($n, 404);
        $hours = ['1h' => 1, '6h' => 6, '24h' => 24, '7d' => 168][$r->query('range', '1h')];
        $samples = DB::table('infra_samples')->where('node_id', $id)->where('observed_at', '>=', now('UTC')->subHours($hours))->orderBy('observed_at')->limit(672)->get()->map(fn ($s) => ['at' => $s->observed_at] + json_decode($s->metrics, true));

        return $this->json(['node' => app(Telemetry::class)->present($n), 'samples' => $samples, 'events' => DB::table('infra_events')->where('subject_id', $id)->orderByDesc('id')->limit(30)->get()->map(fn ($e) => array_merge((array) $e, ['details' => json_decode($e->details, true)]))]);
    }

    public function stepUp(Request $r)
    {
        $actor = $this->actor($r);
        $v = $r->validate(['password' => 'required|string|max:255', 'code' => 'required|string|size:6']);

        return DB::transaction(function () use ($actor, $v) {
            app(ActionWorkflow::class)->lock();
            $u = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($u->api_token_hash === $actor->api_token_hash && $u->mfa_secret && Hash::check($v['password'], $u->password), 403, 'Verify your current password and enrolled authenticator.');
            $proof = app(MfaDevices::class)->consume($u, $v['code']);
            abort_if($proof['recovery_code'], 403, 'Use an enrolled authenticator for infrastructure operations.');
            $id = (string) Str::uuid();
            DB::table('infra_proofs')->where('expires_at', '<', now('UTC'))->delete();
            DB::table('infra_proofs')->insert(['id' => $id, 'actor_id' => $u->id, 'binding' => app(MfaDevices::class)->binding($u), 'expires_at' => now('UTC')->addSeconds(config('infrastructure.step_up_seconds'))]);
            app(Audit::class)->record('security.step_up', null, (string) $u->id, ['authenticator_verified' => true]);

            return $this->json(['proof_id' => $id, 'expires_in' => config('infrastructure.step_up_seconds')]);
        });
    }

    public function enroll(Request $r)
    {
        $actor = $this->actor($r);
        $v = $r->validate(['code' => ['required', 'regex:/^[A-Z]{2,8}-[0-9]{2,3}$/'], 'label' => 'required|string|min:3|max:120', 'tier' => 'required|in:load_balancer,web,database,storage', 'ha_group' => 'required|regex:/^[A-Z0-9-]{3,50}$/', 'environment' => 'required|in:production,staging', 'site' => 'required|string|min:3|max:100', 'reason' => 'required|string|min:10|max:500', 'proof_id' => 'required|uuid']);

        return DB::transaction(function () use ($actor, $v) {
            app(ActionWorkflow::class)->lock();
            app(ActionWorkflow::class)->consumeProof($actor, $v['proof_id']);
            $old = DB::table('infra_nodes')->where('code', $v['code'])->first();
            abort_if($old && $old->enrollment === 'enrolled', 409, 'Enrolled identity changes require operator certificate rotation.');
            abort_if(! $old && DB::table('infra_nodes')->count() >= 100, 422, 'Inventory limit reached. Review infrastructure capacity.');
            $id = $old->id ?? (string) Str::uuid();
            $row = array_intersect_key($v, array_flip(['code', 'label', 'tier', 'ha_group', 'environment', 'site'])) + ['enrollment' => 'pending', 'updated_at' => now('UTC')];
            if ($old) {
                DB::table('infra_nodes')->where('id', $id)->update($row + ['revision' => $old->revision + 1]);
            } else {
                DB::table('infra_nodes')->insert($row + ['id' => $id, 'created_at' => now('UTC')]);
            }
            app(Audit::class)->record('node.enrollment_requested', $id, (string) $actor->id, ['code' => $v['code'], 'reason' => $v['reason']]);

            return $this->json(['message' => 'Inventory saved. An infrastructure operator must verify and pin the node identity before telemetry or control is trusted.', 'id' => $id], 201);
        });
    }

    public function maintenance(Request $r, string $id)
    {
        $actor = $this->actor($r);
        $v = $r->validate(['start' => ['required', 'date', 'after:'.now('UTC')->subMinutes(2)->toIso8601String()], 'end' => 'required|date|after:start|before:'.now('UTC')->addDay()->toIso8601String(), 'reason' => 'required|string|min:10|max:500', 'revision' => 'required|integer|min:1', 'proof_id' => 'required|uuid']);

        return DB::transaction(function () use ($actor, $v, $id) {
            app(ActionWorkflow::class)->lock();
            $n = DB::table('infra_nodes')->where('id', $id)->first();
            abort_unless($n, 404);
            abort_unless($n->revision === $v['revision'], 409, 'Inventory changed; refresh the node.');
            app(ActionWorkflow::class)->consumeProof($actor, $v['proof_id']);
            DB::table('infra_nodes')->where('id', $id)->update(['maintenance_start' => CarbonImmutable::parse($v['start'])->utc(), 'maintenance_end' => CarbonImmutable::parse($v['end'])->utc(), 'maintenance_reason' => $v['reason'], 'revision' => $n->revision + 1, 'updated_at' => now('UTC')]);
            app(Audit::class)->record('node.maintenance_scheduled', $id, (string) $actor->id, ['start' => $v['start'], 'end' => $v['end'], 'reason' => $v['reason']]);

            return $this->json(['message' => 'Maintenance window recorded. No service has been stopped or drained.']);
        });
    }

    public function preflight(ActionRequest $r)
    {
        $v = $r->validated();
        $node = DB::table('infra_nodes')->where('id', $v['node_id'])->first();
        abort_unless($node, 404);

        return $this->json(app(Preflight::class)->inspect($node, $v['action'], $v['parameters']));
    }

    public function createAction(ActionRequest $r)
    {
        return $this->json(['data' => app(ActionWorkflow::class)->create($this->actor($r), $r->validated())], 201);
    }

    public function review(Request $r, string $id)
    {
        $actor = $this->actor($r);
        $v = $r->validate(['decision' => 'required|in:approve,reject,cancel', 'revision' => 'required|integer|min:1', 'note' => 'required|string|min:10|max:500', 'proof_id' => 'required|uuid']);

        return $this->json(['data' => app(ActionWorkflow::class)->review($actor, $id, $v)]);
    }

    public function actions(Request $r)
    {
        $this->actor($r);
        $r->validate(['page' => 'nullable|integer|min:1|max:100000', 'node_id' => 'nullable|uuid', 'state' => 'nullable|in:blocked,awaiting_approval,scheduled,running,validating,uncertain,succeeded,failed,rolled_back,cancelled,rejected,expired']);
        $p = DB::table('infra_actions')->when($r->query('node_id'), fn ($q, $id) => $q->where('node_id', $id))->when($r->query('state'), fn ($q, $s) => $q->where('state', $s))->orderByDesc('created_at')->paginate(15);

        return $this->json(['data' => collect($p->items())->map(fn ($row) => app(ActionWorkflow::class)->present($row)), 'total' => $p->total(), 'last_page' => $p->lastPage()]);
    }

    public function events(Request $r)
    {
        $this->actor($r);
        $r->validate(['page' => 'nullable|integer|min:1|max:100000']);
        $p = DB::table('infra_events')->orderByDesc('id')->paginate(25);

        return $this->json(['data' => collect($p->items())->map(fn ($row) => array_merge((array) $row, ['details' => json_decode($row->details, true)])), 'total' => $p->total(), 'last_page' => $p->lastPage()]);
    }

    public function export(Request $r)
    {
        $u = $this->actor($r);
        app(Audit::class)->record('inventory.exported', null, (string) $u->id, ['format' => 'csv']);
        $nodes = DB::table('infra_nodes')->orderBy('code')->get();

        return response()->streamDownload(function () use ($nodes) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Server', 'Role', 'Environment', 'Site', 'Enrollment', 'Current status', 'Observed at (UTC)', 'CPU %', 'Memory %', 'Disk %'], ',', '"', '');
            foreach ($nodes as $n) {
                $p = app(Telemetry::class)->present($n);
                $t = $p['fresh'] ? $p['telemetry'] : [];
                $row = [$n->code, $n->label, $n->environment, $n->site, $n->enrollment, $p['status'], $n->observed_at, $t['cpu_percent'] ?? '', $t['memory_percent'] ?? '', $t['disk_percent'] ?? ''];
                fputcsv($out, array_map(fn ($s) => preg_match('/^[=+@\-\t\r\n]/', (string) $s) ? "'".$s : $s, $row), ',', '"', '');
            }
            fclose($out);
        }, 'NUERS-server-inventory-'.now('UTC')->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
