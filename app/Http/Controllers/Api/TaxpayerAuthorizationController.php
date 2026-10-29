<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BirCompliance;
use App\Services\RegistrationAddress;
use App\Services\TaxpayerAuthority;
use Carbon\CarbonImmutable as C;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaxpayerAuthorizationController extends Controller
{
    private function actor(Request $r, bool $write = false)
    {
        $u = app(BirCompliance::class)->administrativeUser($r);
        if ($write) {
            abort_unless(in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin', 'super_admin']), 403);
        }

        return $u;
    }

    private function lock()
    {
        DB::table('business_registry_lock')->where('id', 1)->lockForUpdate()->first();
    }

    public function effective(object $p): string
    {
        return app(TaxpayerAuthority::class)->status($p);
    }

    private function permits()
    {
        return DB::table('taxpayer_authorizations as p')->join('merchants as m', 'm.id', '=', 'p.merchant_id')->leftJoin('rdo_offices as r', 'r.rdo_code', '=', 'm.rdo_code')->select('p.*', 'm.business_name', 'm.tin', 'm.rdo_code', 'r.rdo_name')->get()->map(function ($p) {
            $p->effective_status = $this->effective($p);
            $p->device_count = DB::table('taxpayer_devices')->where('authorization_id', $p->id)->count();

            return $p;
        });
    }

    private function filtered(Request $r, $rows)
    {
        foreach (['kind', 'status', 'environment', 'merchant_id', 'rdo_code'] as $k) {
            if ($r->filled($k) && $r->$k !== 'all') {
                $rows = $rows->where($k === 'status' ? 'effective_status' : $k, $r->$k);
            }
        }if ($r->filled('search')) {
            $s = mb_strtolower($r->search);
            $rows = $rows->filter(fn ($p) => str_contains(mb_strtolower(implode(' ', [$p->reference, $p->business_name, $p->tin, $p->scope])), $s));
        }if ($r->filled('from')) {
            $rows = $rows->where('issued_at', '>=', $r->from);
        }if ($r->filled('to')) {
            $rows = $rows->where('issued_at', '<=', $r->to);
        }if ($r->validity === 'expiring') {
            $rows = $rows->where('effective_status', 'expiring_soon');
        }if ($r->validity === 'expired') {
            $rows = $rows->where('effective_status', 'expired');
        }if ($r->validity === 'current') {
            $rows = $rows->whereIn('effective_status', ['active', 'expiring_soon']);
        }

        return ($r->sort === 'oldest' ? $rows->sortBy('created_at') : $rows->sortByDesc('created_at'))->values();
    }

    public function index(Request $r)
    {
        $u = $this->actor($r);
        $r->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100', 'search' => 'nullable|string|max:255', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $all = $this->permits();
        $permitRequest = clone $r;
        if (in_array($r->tab, ['devices', 'logs', 'audit'])) {
            $permitRequest->merge(['status' => 'all']);
        }
        $rows = $this->filtered($permitRequest, $all);
        $devices = DB::table('taxpayer_devices as d')->join('taxpayer_authorizations as p', 'p.id', '=', 'd.authorization_id')->join('merchants as m', 'm.id', '=', 'p.merchant_id')->select('d.*', 'p.reference', 'p.environment', 'p.merchant_id', 'm.rdo_code', 'm.business_name', 'm.tin')->orderByDesc('d.created_at')->get();
        foreach ($devices as $d) {
            $p = $all->firstWhere('id', $d->authorization_id);
            $d->permit_status = $p?->effective_status;
            $d->connection_status = 'unverified';
            $d->last_observation = DB::table('taxpayer_connection_events')->where('device_id', $d->id)->orderByDesc('observed_at')->first();
        }
        $devices = $devices->filter(function ($d) use ($r) {
            if ($r->filled('search') && ! str_contains(mb_strtolower(implode(' ', [$d->name, $d->serial_number, $d->business_name, $d->tin, $d->reference])), mb_strtolower($r->search))) {
                return false;
            }foreach (['environment', 'merchant_id', 'rdo_code'] as $k) {
                if ($r->filled($k) && $r->$k !== 'all' && $d->$k !== $r->$k) {
                    return false;
                }
            }

            return true;
        })->values();
        $ids = $rows->pluck('id');
        $events = DB::table('taxpayer_connection_events as e')->join('taxpayer_devices as d', 'd.id', '=', 'e.device_id')->whereIn('d.id', $devices->pluck('id'))->when($r->tab === 'logs' && $r->filled('status') && $r->status !== 'all', fn ($q) => $q->where('e.status', $r->status))->when($r->filled('from'), fn ($q) => $q->whereDate('e.observed_at', '>=', $r->from))->when($r->filled('to'), fn ($q) => $q->whereDate('e.observed_at', '<=', $r->to))->select('e.*', 'd.name', 'd.serial_number')->orderByDesc('e.observed_at')->get();
        $audit = DB::table('eis_audit_events')->where('event', 'like', 'permit.%')->whereIn('subject_id', $ids->merge($devices->pluck('id')))->orderByDesc('id')->get();
        $tab = $r->tab;
        $list = $tab === 'devices' ? $devices : ($tab === 'logs' ? $events : ($tab === 'audit' ? $audit : ($tab === 'renewals' ? $rows->whereIn('effective_status', ['expired', 'expiring_soon', 'not_yet_valid'])->values() : $rows)));
        $size = (int) ($r->per_page ?? 10);
        $page = (int) ($r->page ?? 1);
        $total = $list->count();
        $chart = fn ($kind) => $all->where('kind', $kind)->groupBy('effective_status')->map(fn ($g, $k) => ['name' => $k, 'value' => $g->count()])->values();

        return response()->json(['data' => $list->forPage($page, $size)->values(), 'total' => $total, 'last_page' => max(1, ceil($total / $size)), 'summary' => ['pti' => $all->where('kind', 'PTI')->count(), 'active' => $all->where('kind', 'PTI')->whereIn('effective_status', ['active', 'expiring_soon'])->count(), 'ptt' => $all->where('kind', 'PTT')->count(), 'connected' => 0, 'expiring' => $all->where('effective_status', 'expiring_soon')->count(), 'inactive' => $all->whereIn('effective_status', ['expired', 'suspended', 'revoked'])->count()], 'charts' => ['pti' => $chart('PTI'), 'ptt' => $chart('PTT')], 'options' => ['rdos' => app(RegistrationAddress::class)->offices(), 'businesses' => DB::table('merchants')->orderBy('business_name')->get(['id', 'business_name', 'tin', 'rdo_code']), 'permits' => $all->where('kind', 'PTT')->values()->map(fn ($x) => ['id' => $x->id, 'reference' => $x->reference, 'business_name' => $x->business_name, 'environment' => $x->environment, 'effective_status' => $x->effective_status])], 'can_write' => in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin', 'super_admin']), 'generated_at' => now()->toIso8601String(), 'notice' => 'PTI = Permit to Issue; PTT = Permit to Transmit. These are taxpayer authorities, not terminal types. Recorded permits do not establish BIR connectivity.']);
    }

    private function validated(array $data, ?string $id = null)
    {
        $data['reference'] = trim($data['reference'] ?? '');
        $v = Validator::make($data, ['merchant_id' => 'required|exists:merchants,id', 'kind' => 'required|in:PTI,PTT', 'reference' => ['required', 'string', 'max:120', Rule::unique('taxpayer_authorizations')->where('kind', $data['kind'] ?? '')->where('merchant_id', $data['merchant_id'] ?? '')->where('environment', $data['environment'] ?? '')->where('branch_code', $data['branch_code'] ?? null)->ignore($id)], 'environment' => 'required|in:certification,production', 'branch_code'=>'nullable|regex:/^[0-9]{5}$/', 'system_reference' => 'nullable|string|max:160', 'branch_notification_reference' => 'nullable|string|max:1000', 'scope' => 'required|string|min:5|max:2000', 'issued_at' => 'required|date_format:Y-m-d', 'valid_until' => 'nullable|date_format:Y-m-d|after_or_equal:issued_at', 'status' => 'required|in:pending,active,suspended,revoked', 'evidence' => 'required|string|min:10|max:3000', 'reason' => 'required|string|min:10|max:2000'])->validate();
        $v['reference'] = trim($v['reference']);
        $siblings = DB::table('taxpayer_authorizations')->where('kind', $v['kind'])->where('reference', $v['reference'])->when($id, fn ($q) => $q->where('id', '!=', $id))->get();
        foreach ($siblings as $sibling) {
            abort_unless($sibling->merchant_id === $v['merchant_id'], 422, 'This authority reference belongs to another taxpayer.');
            abort_unless($v['kind'] === 'PTI' && !empty($v['system_reference']) && $sibling->system_reference === $v['system_reference'], 422, 'A shared PTI requires the same evidenced approved system. Different systems require separate PTIs.');
            abort_unless(!empty($v['branch_notification_reference']), 422, 'Record the branch notification / coverage evidence for the shared PTI.');
        }
        abort_if($v['reference'] === '', 422, 'A permit reference is required.');
        if ($v['status'] === 'active') {
            abort_unless(!empty($v['branch_code']),422,'Active authority requires its verified five-digit BIR branch code.');
            abort_if(C::parse($v['issued_at'])->isFuture(), 422, 'An active permit must already be effective.');
        }

        return $v;
    }

    public function store(Request $r)
    {
        $u = $this->actor($r, true);
        $r->validate(['request_id' => 'required|uuid']);

        return DB::transaction(function () use ($r, $u) {
            $this->lock();
            $hash = hash('sha256', json_encode($r->except('request_id')));
            $old = DB::table('taxpayer_authorizations')->where('request_id', $r->request_id)->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Request ID already used for different content.');

                return response()->json(['record' => $old, 'duplicate' => true]);
            }$v = $this->validated($r->all());
            $id = (string) Str::uuid();
            DB::table('taxpayer_authorizations')->insert($v + ['id' => $id, 'request_id' => $r->request_id, 'request_hash' => $hash, 'revision' => 1, 'reviewed_by' => $u->id, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            app(BirCompliance::class)->audit('permit.registered', $id, $u->id, $v);

            return response()->json(['record' => DB::table('taxpayer_authorizations')->find($id)], 201);
        });
    }

    public function update(Request $r, string $id)
    {
        $u = $this->actor($r, true);
        $r->validate(['revision' => 'required|integer|min:1']);

        return DB::transaction(function () use ($r, $u, $id) {
            $this->lock();
            $old = DB::table('taxpayer_authorizations')->where('id', $id)->first();
            abort_unless($old, 404);
            abort_unless($old->revision === $r->integer('revision'), 409, 'Record changed. Refresh before saving.');
            $v = $this->validated($r->all(), $id);
            abort_if($old->status === 'revoked' && $v['status'] !== 'revoked', 422, 'A revoked authority cannot be reactivated. Register its replacement.');
            abort_unless($v['merchant_id'] === $old->merchant_id && $v['kind'] === $old->kind && $v['environment'] === $old->environment && $v['reference'] === $old->reference, 422, 'Taxpayer, authority type, environment and permit reference are immutable. Register a replacement for a new authority.');
            DB::table('taxpayer_authorizations')->where('id', $id)->update($v + ['revision' => $old->revision + 1, 'reviewed_by' => $u->id, 'reviewed_at' => now(), 'updated_at' => now()]);
            app(BirCompliance::class)->audit('permit.reviewed', $id, $u->id, ['before' => $old, 'after' => $v]);

            return response()->json(['record' => DB::table('taxpayer_authorizations')->find($id)]);
        });
    }

    public function show(Request $r, string $id)
    {
        $this->actor($r);
        $p = $this->permits()->firstWhere('id', $id);
        abort_unless($p, 404);

        return response()->json(['record' => $p, 'devices' => DB::table('taxpayer_devices')->where('authorization_id', $id)->get(), 'history' => DB::table('eis_audit_events')->where('subject_id', $id)->orderByDesc('id')->get()]);
    }

    public function device(Request $r, ?string $id = null)
    {
        $u = $this->actor($r, true);
        $v = $r->validate(['authorization_id' => 'required|exists:taxpayer_authorizations,id', 'name' => 'required|string|max:120', 'serial_number' => ['required', 'string', 'max:120', Rule::unique('taxpayer_devices')->ignore($id)], 'branch' => 'required|string|max:120', 'device_type' => 'required|in:pos,server,workstation,cloud_connector', 'status' => 'required|in:registered,maintenance,deactivated', 'reason' => 'required|string|min:10|max:2000', 'revision' => 'required|integer|min:0', 'request_id' => 'required|uuid']);

        return DB::transaction(function () use ($v, $u, $id) {
            $this->lock();
            $p = DB::table('taxpayer_authorizations')->find($v['authorization_id']);
            abort_unless($p && $p->kind === 'PTT', 422, 'A device must be bound to a Permit to Transmit.');
            if ($v['status'] === 'registered') {
                abort_unless(in_array($this->effective($p), ['active', 'expiring_soon']), 422, 'Registration requires a current PTT authority.');
            }$old = $id ? DB::table('taxpayer_devices')->find($id) : null;
            if ($id) {
                abort_unless($old, 404);
                abort_unless($old->revision === $v['revision'], 409, 'Device changed. Refresh before saving.');
                abort_unless($old->authorization_id === $v['authorization_id'], 422, 'Device authority cannot be reassigned. Deactivate and register a new device.');
            } else {
                abort_if(DB::table('taxpayer_devices')->where('request_id', $v['request_id'])->exists(), 409, 'Device request already completed. Refresh the device list.');
            }$data = collect($v)->except(['revision', 'request_id'])->all() + ['revision' => ($old?->revision ?? 0) + 1, 'updated_at' => now()];
            $deviceId = $id ?? (string) Str::uuid();
            if ($old) {
                DB::table('taxpayer_devices')->where('id', $id)->update($data);
            } else {
                DB::table('taxpayer_devices')->insert($data + ['id' => $deviceId, 'request_id' => $v['request_id'], 'request_hash' => hash('sha256', json_encode($v)), 'created_at' => now()]);
            }app(BirCompliance::class)->audit('permit.device_saved', $deviceId, $u->id, ['before' => $old, 'after' => $data]);

            return response()->json(['record' => DB::table('taxpayer_devices')->find($deviceId)], $old ? 200 : 201);
        });
    }

    public function observation(Request $r, string $id)
    {
        $u = $this->actor($r, true);
        $v = $r->validate(['request_id' => 'required|uuid', 'status' => 'required|in:unverified,disconnected,maintenance', 'evidence' => 'required|string|min:10|max:3000', 'reason' => 'required|string|min:10|max:2000', 'observed_at' => 'required|date|before_or_equal:now']);

        return DB::transaction(function () use ($id, $v, $u) {
            $this->lock();
            abort_unless(DB::table('taxpayer_devices')->find($id), 404);
            $old = DB::table('taxpayer_connection_events')->where('request_id', $v['request_id'])->first();
            if ($old) {
                abort_unless($old->device_id === $id && $old->status === $v['status'] && $old->evidence === $v['evidence'] && $old->reason === $v['reason'], 409);

                return response()->json(['record' => $old, 'duplicate' => true]);
            }$eid = (string) Str::uuid();
            DB::table('taxpayer_connection_events')->insert($v + ['id' => $eid, 'device_id' => $id, 'recorded_by' => $u->id, 'created_at' => now()]);
            app(BirCompliance::class)->audit('permit.connection_observed', $id, $u->id, ['observation_id' => $eid] + $v);

            return response()->json(['record' => DB::table('taxpayer_connection_events')->find($eid)], 201);
        });
    }

    private function csv(array $header, $rows, string $filename)
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, $header);
            foreach ($rows as $row) {
                fputcsv($f, array_map(fn ($x) => preg_match('/^[\s]*[=+@\-]/u', (string) $x) ? "'".$x : $x, $row));
            }fclose($f);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private const HEAD = ['merchant_id', 'kind', 'reference', 'environment', 'scope', 'issued_at', 'valid_until', 'evidence'];

    public function template(Request $r)
    {
        $this->actor($r);

        return $this->csv(self::HEAD, [], 'NUERS-permit-template.csv');
    }

    public function export(Request $r)
    {
        $u = $this->actor($r);
        $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'search' => 'nullable|string|max:255']);
        $rows = $this->filtered($r, $this->permits());
        if ($r->has('report_limit')) \App\Services\ReportCsv::limit($r,$rows->count());
        app(BirCompliance::class)->audit('permit.exported', null, $u->id, ['count' => $rows->count()]);
        $head = ['reference', 'kind', 'business_name', 'tin', 'rdo_code', 'environment', 'scope', 'issued_at', 'valid_until', 'effective_status', 'evidence'];

        return $this->csv($head, $rows->map(fn ($p) => array_map(fn ($k) => $p->$k ?? '', $head)), 'NUERS-permits.csv');
    }

    public function preview(Request $r)
    {
        $u = $this->actor($r, true);
        $r->validate(['file' => 'required|file|max:2048']);
        $raw = file_get_contents($r->file('file')->getRealPath());
        abort_unless(mb_check_encoding($raw, 'UTF-8'), 422, 'Upload a UTF-8 CSV.');
        $f = fopen('php://temp', 'r+');
        fwrite($f, preg_replace('/^\xEF\xBB\xBF/', '', $raw));
        rewind($f);
        abort_unless(fgetcsv($f) === self::HEAD, 422, 'Use the supplied template headers.');
        $rows = [];
        $errors = [];
        $seen = [];
        while (($line = fgetcsv($f)) !== false) {
            if ($line === [null]) {
                continue;
            }abort_if(count($rows) >= 500, 422, 'Maximum 500 rows.');
            $n = count($rows) + 2;
            if (count($line) !== count(self::HEAD)) {
                $errors[] = ['row' => $n, 'message' => 'Column count does not match.'];
                $rows[] = [];

                continue;
            }$p = array_combine(self::HEAD, array_map('trim', $line));
            $p['valid_until'] = $p['valid_until'] ?: null;
            $p += ['status' => 'pending', 'reason' => 'Imported for documentary review'];
            try {
                $p = $this->validated($p);
                $k = $p['kind'].'|'.$p['reference'];
                abort_if(isset($seen[$k]), 422, 'Duplicate authority in file.');
                $seen[$k] = true;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $n, 'message' => $e instanceof ValidationException ? implode(' ', array_merge(...array_values($e->errors()))) : $e->getMessage()];
            }$rows[] = $p;
        }fclose($f);
        abort_unless(count($rows), 422, 'CSV contains no rows.');
        $id = (string) Str::uuid();
        if (! $errors) {
            DB::table('business_registry_requests')->insert(['id' => $id, 'actor_id' => $u->id, 'purpose' => 'permit_import', 'request_hash' => hash('sha256', $raw), 'payload' => Crypt::encryptString(json_encode($rows)), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return response()->json(['id' => $errors ? null : $id, 'rows' => count($rows), 'errors' => $errors, 'preview' => array_slice($rows, 0, 20)]);
    }

    public function commit(Request $r, string $id)
    {
        $u = $this->actor($r, true);

        return DB::transaction(function () use ($id, $u) {
            $this->lock();
            $job = DB::table('business_registry_requests')->where('id', $id)->where('actor_id', $u->id)->where('purpose', 'permit_import')->first();
            abort_unless($job, 404);
            $rows = json_decode(Crypt::decryptString($job->payload), true);
            if ($job->completed_at) {
                return response()->json(['imported' => count($rows), 'duplicate' => true]);
            }abort_if(C::parse($job->expires_at)->isPast(), 410, 'Preview expired. Upload again.');
            foreach ($rows as $p) {
                $v = $this->validated($p);
                $pid = (string) Str::uuid();
                DB::table('taxpayer_authorizations')->insert($v + ['id' => $pid, 'request_id' => (string) Str::uuid(), 'request_hash' => hash('sha256', json_encode($v)), 'revision' => 1, 'reviewed_by' => $u->id, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                app(BirCompliance::class)->audit('permit.imported', $pid, $u->id, $v);
            }DB::table('business_registry_requests')->where('id', $id)->update(['completed_at' => now(), 'updated_at' => now()]);

            return response()->json(['imported' => count($rows)]);
        });
    }
}
