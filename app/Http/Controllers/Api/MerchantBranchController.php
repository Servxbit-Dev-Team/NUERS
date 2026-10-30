<?php

namespace App\Http\Controllers\Api;

use App\Services\ApiAccess;
use App\Services\MerchantBranches as Registry;
use App\Services\RegistrationAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MerchantBranchController
{
    private function registry(): Registry
    {
        return app(Registry::class);
    }

    private function member(Request $r): object
    {
        return app(ApiAccess::class)->merchant($r);
    }

    private function filters(Request $r): array
    {
        return $r->validate(['search' => 'nullable|string|max:80', 'status' => 'nullable|in:active,inactive', 'city' => 'nullable|string|max:100', 'type' => 'nullable|in:main,branch', 'branch_id' => 'nullable|uuid', 'terminal_search' => 'nullable|string|max:80', 'terminal_branch' => 'nullable|uuid', 'pos_type' => 'nullable|in:pos,mobile_pos,web,erp,api', 'terminal_status' => 'nullable|in:active,inactive', 'activity' => 'nullable|in:recent,unobserved', 'page' => 'sometimes|integer|min:1|max:100000', 'terminal_page' => 'sometimes|integer|min:1|max:100000', 'per_page' => 'sometimes|integer|in:10,25']);
    }

    private function branches(string $merchant, array $v)
    {
        $q = DB::table('merchant_branches')->where('merchant_id', $merchant);
        foreach (['status', 'city', 'type' => 'branch_type'] as $k => $col) {
            $field = is_int($k) ? $col : $k;
            if (! empty($v[$field])) {
                if ($field === 'city' && str_starts_with($v[$field], 'region:')) {
                    $q->where('region', substr($v[$field], 7));
                } else {
                    $q->where($col, $v[$field]);
                }
            }
        }if (! empty($v['search'])) {
            $s = '%'.$v['search'].'%';
            $q->where(fn ($x) => $x->where('name', 'like', $s)->orWhere('code', 'like', $s)->orWhere('address', 'like', $s)->orWhere('bir_branch_code', 'like', $s)->orWhere('rdo_code', 'like', $s));
        }

        return $q;
    }

    private function terminalQuery(string $merchant, array $v)
    {
        $q = DB::table('merchant_terminals')->where('merchant_id', $merchant);
        foreach (['terminal_branch' => 'branch_id', 'pos_type' => 'pos_type', 'terminal_status' => 'status'] as $k => $col) {
            if (! empty($v[$k])) {
                $q->where($col, $v[$k]);
            }
        }if (! empty($v['terminal_search'])) {
            $s = '%'.$v['terminal_search'].'%';
            $q->where(fn ($x) => $x->where('name', 'like', $s)->orWhere('identifier', 'like', $s)->orWhere('code', 'like', $s));
        }if (! empty($v['activity'])) {
            $predicate = function ($x) use ($merchant) {
                $x->selectRaw('1')->from('integration_inbox as i')->where('i.merchant_id', $merchant)->whereColumn('i.api_key_id', 'merchant_terminals.api_key_id')->where('i.received_at', '>=', now()->subMinutes(15));
            };
            $v['activity'] === 'recent' ? $q->whereExists($predicate) : $q->whereNotExists($predicate);
        }

        return $q;
    }

    public function index(Request $r)
    {
        $m = $this->member($r);
        $v = $this->filters($r);
        foreach (['branch_id', 'terminal_branch'] as $k) {
            if (! empty($v[$k])) {
                $this->registry()->branch($m->id, $v[$k]);
            }
        }
        $branches = $this->branches($m->id, $v)->orderByRaw("CASE WHEN branch_type='main' THEN 0 ELSE 1 END")->orderBy('name')->paginate($v['per_page'] ?? 10, ['*'], 'page', $v['page'] ?? 1);
        $terminals = $this->terminalQuery($m->id, $v)->orderBy('name')->paginate($v['per_page'] ?? 10, ['*'], 'terminal_page', $v['terminal_page'] ?? 1);
        $counts = DB::table('merchant_terminals')->where('merchant_id', $m->id)->select('branch_id')->selectRaw('COUNT(*) as total,SUM(CASE WHEN status=\'active\' THEN 1 ELSE 0 END) as active')->groupBy('branch_id')->get()->keyBy('branch_id');
        $rows = collect($branches->items())->map(fn ($b) => [...$this->registry()->branchRow($b), 'terminal_count' => (int) ($counts[$b->id]->total ?? 0)]);
        $selected = ! empty($v['branch_id']) ? $this->registry()->branch($m->id, $v['branch_id']) : ($branches->items()[0] ?? null);
        $details = null;
        if ($selected) {
            $docs = DB::table('merchant_branch_documents')->where('merchant_id', $m->id)->where('branch_id', $selected->id)->orderByDesc('created_at')->get();
            $b = $this->registry()->branchRow($selected);
            $checks = ['branch_information' => (bool) ($b['name'] && $b['bir_branch_code']), 'address' => (bool) ($b['address'] && $b['city'] && $b['region']), 'contact' => (bool) ($b['manager_name'] && $b['phone']), 'tax_information' => (bool) ($b['bir_branch_code'] && $b['rdo_code']), 'documents' => count(array_intersect(array_keys(Registry::DOCUMENTS), $docs->pluck('kind')->unique()->all())) === count(Registry::DOCUMENTS), 'terminal_configuration' => (int) ($counts[$selected->id]->total ?? 0) > 0];
            $details = ['branch' => $b, 'readiness' => $this->registry()->readiness($selected), 'checks' => $checks, 'completion' => (int) round(count(array_filter($checks)) / count($checks) * 100), 'terminals' => (int) ($counts[$selected->id]->total ?? 0), 'active_terminals' => (int) ($counts[$selected->id]->active ?? 0), 'documents' => $docs->map(fn ($d) => ['id' => $d->id, 'kind' => $d->kind, 'name' => Crypt::decryptString($d->filename_encrypted), 'bytes' => $d->bytes, 'created_at' => $d->created_at])->values()];
        }
        $base = DB::table('merchant_branches')->where('merchant_id', $m->id);
        $last = collect([(clone $base)->max('updated_at'), DB::table('merchant_terminals')->where('merchant_id', $m->id)->max('updated_at')])->filter()->max();
        $keys = DB::table('merchant_api_keys')->where('merchant_id', $m->id)->select('id', 'name', 'branch_code', 'status', 'environment', 'expires_at')->orderBy('name')->limit(1000)->get();
        $devices = DB::table('taxpayer_devices as d')->join('taxpayer_authorizations as a', 'a.id', '=', 'd.authorization_id')->where('a.merchant_id', $m->id)->select('d.id', 'd.name', 'd.branch', 'd.status', 'a.branch_code', 'a.environment')->orderBy('d.name')->limit(1000)->get();

        return response()->json(['branches' => ['data' => $rows, 'total' => $branches->total(), 'page' => $branches->currentPage(), 'last_page' => $branches->lastPage()], 'terminals' => ['data' => $this->registry()->terminals($m->id, array_map(fn ($t) => (array) $t, $terminals->items())), 'total' => $terminals->total(), 'page' => $terminals->currentPage(), 'last_page' => $terminals->lastPage()], 'selected' => $details, 'summary' => ['total_branches' => (clone $base)->count(), 'active_branches' => (clone $base)->where('status', 'active')->count(), 'registered_terminals' => DB::table('merchant_terminals')->where('merchant_id', $m->id)->count(), 'active_terminals' => DB::table('merchant_terminals')->where('merchant_id', $m->id)->where('status', 'active')->whereIn('branch_id', (clone $base)->where('status', 'active')->select('id'))->count(), 'pending_verification' => (clone $base)->where('review_status', 'pending_review')->count(), 'latest_update' => $last], 'options' => ['branches' => (clone $base)->select('id', 'code', 'name', 'bir_branch_code', 'status')->orderBy('name')->limit(500)->get(), 'cities' => (clone $base)->distinct()->orderBy('city')->pluck('city'), 'regions' => (clone $base)->distinct()->orderBy('region')->pluck('region'), 'keys' => $keys, 'terminals' => DB::table('merchant_terminals')->where('merchant_id', $m->id)->orderBy('name')->limit(1000)->get(['id', 'code', 'name', 'identifier', 'branch_id', 'pos_type', 'status', 'api_key_id', 'taxpayer_device_id', 'revision']), 'devices' => $devices, 'rdos' => app(RegistrationAddress::class)->offices(), 'document_kinds' => Registry::DOCUMENTS], 'activities' => DB::table('merchant_branch_events')->where('merchant_id', $m->id)->orderByDesc('created_at')->limit(8)->get(['id', 'branch_id', 'action', 'created_at']), 'merchant' => ['name' => $m->business_name, 'tin' => $m->tin, 'registered_address' => app(RegistrationAddress::class)->merchantFormValue($m)], 'updated_at' => now()->toIso8601String()])->header('Cache-Control', 'private, no-store');
    }

    private function branchInput(Request $r, bool $edit): array
    {
        $v = $r->validate(['name' => 'required|string|min:2|max:160', 'bir_branch_code' => ['nullable', 'regex:/^\d{5}$/'],
            'address' => 'nullable|required_without:structured_address|string|min:8|max:1000', 'city' => 'nullable|required_without:structured_address|string|min:2|max:100', 'region' => 'nullable|required_without:structured_address|string|min:2|max:100',
            'rdo_code' => ['nullable', 'required_without:structured_address', Rule::in(array_column(app(RegistrationAddress::class)->offices(), 'rdo_code'))],
            'structured_address' => 'nullable|array:region_code,province_code,city_code,barangay_code,street_address,zip_code,rdo_code,address',
            'structured_address.region_code' => 'required_with:structured_address|digits:10', 'structured_address.province_code' => 'required_with:structured_address|digits:10',
            'structured_address.city_code' => 'required_with:structured_address|digits:10', 'structured_address.barangay_code' => 'required_with:structured_address|digits:10',
            'structured_address.street_address' => 'required_with:structured_address|string|min:1|max:500', 'structured_address.zip_code' => 'required_with:structured_address|digits:4',
            'structured_address.rdo_code' => ['nullable', Rule::in(array_column(app(RegistrationAddress::class)->offices(), 'rdo_code'))], 'structured_address.address' => 'nullable|string|max:1000',
            'branch_type' => 'required|in:main,branch', 'status' => 'required|in:active,inactive', 'manager_name' => 'nullable|string|min:2|max:160', 'email' => 'nullable|email:rfc|max:254',
            'phone' => ['nullable', 'regex:/^(?=(?:\D*\d){7,15}\D*$)\+?[0-9 ()-]{7,30}$/'], 'reason' => 'required|string|min:10|max:1000',
            ...($edit ? ['revision' => 'required|integer|min:1'] : ['request_id' => 'required|uuid']), 'review_status' => 'prohibited', 'merchant_id' => 'prohibited', 'reviewed_by' => 'prohibited']);
        if (!empty($v['structured_address'])) {
            $a = $v['structured_address'];
            $address = app(RegistrationAddress::class);
            $resolved = $address->resolve($a['city_code'], $a['barangay_code']);
            $a['rdo_code'] = $a['rdo_code'] ?? $v['rdo_code'] ?? $resolved['rdo']['rdo_code'] ?? '';
            abort_unless($a['rdo_code'] !== '', 422, 'Confirm the registered RDO for this branch address.');
            $canonical = $address->validate($a);
            $v['address'] = $canonical['address'];
            $v['city'] = $canonical['details']['city']['name'];
            $v['region'] = $canonical['details']['region']['name'];
            $v['rdo_code'] = $a['rdo_code'];
            $v['address_details'] = json_encode($canonical['details'], JSON_THROW_ON_ERROR);
            // Hash the validated canonical snapshot, not client-provided derived labels.
            unset($v['structured_address']);
        }
        return $v;
    }

    public function store(Request $r)
    {
        return $this->saveBranch($r, null);
    }

    public function update(Request $r, string $id)
    {
        return $this->saveBranch($r, $id);
    }

    private function saveBranch(Request $r, ?string $id)
    {
        $m = $this->member($r);
        $v = $this->branchInput($r, (bool) $id);

        return DB::transaction(function () use ($r, $m, $v, $id) {
            DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
            $old = $id ? $this->registry()->branch($m->id, $id) : null;
            $hash = hash('sha256', json_encode($v));
            if (! $old && ($same = DB::table('merchant_branches')->where('merchant_id', $m->id)->where('request_id', $v['request_id'])->first())) {
                abort_unless(hash_equals($same->request_hash, $hash), 409, 'Request ID was used for different branch details.');

                return response()->json(['data' => $this->registry()->branchRow($same)]);
            }
            if ($old) {
                abort_unless($old->revision === (int) $v['revision'], 409, 'Branch changed. Refresh before saving.');
                abort_if($old->bir_branch_code && $old->bir_branch_code !== ($v['bir_branch_code'] ?? null), 422, 'Saved BIR branch identity cannot be reassigned. Preserve this record and create the correct branch.');
            }
            abort_if(empty($v['bir_branch_code']) && $v['status'] === 'active', 422, 'Record the five-digit BIR branch code before marking this branch operationally active. Draft branches may remain inactive.');
            abort_if(! empty($v['bir_branch_code']) && DB::table('merchant_branches')->where('merchant_id', $m->id)->where('bir_branch_code', $v['bir_branch_code'])->when($old, fn ($q) => $q->where('id', '!=', $old->id))->exists(), 422, 'This BIR branch code is already registered for your business.');
            abort_if($v['branch_type'] === 'main' && DB::table('merchant_branches')->where('merchant_id', $m->id)->where('branch_type', 'main')->when($old, fn ($q) => $q->where('id', '!=', $old->id))->exists(), 422, 'A head office is already recorded. Choose Branch.');
            $contact = array_intersect_key($v, array_flip(['manager_name', 'email', 'phone']));
            foreach (['manager_name', 'email', 'phone'] as $k) {
                $contact[$k] = $contact[$k] ?? null;
            }
            $row = array_intersect_key($v, array_flip(['name', 'bir_branch_code', 'address', 'city', 'region', 'rdo_code', 'branch_type', 'status']));
            $sameAddress = $old && collect(['address', 'city', 'region', 'rdo_code'])->every(fn ($k) => ($old->$k ?? null) === ($row[$k] ?? null));
            $row['address_details'] = $v['address_details'] ?? ($sameAddress ? ($old->address_details ?? null) : null);
            $row['bir_branch_code'] = $row['bir_branch_code'] ?? null;
            $row += ['contact_encrypted' => Crypt::encryptString(json_encode($contact)), 'revision' => $old ? $old->revision + 1 : 1, 'updated_at' => now()];
            $material = ! $old || collect(['name', 'bir_branch_code', 'address', 'city', 'region', 'rdo_code', 'branch_type'])->contains(fn ($k) => ($old->$k ?? null) !== $row[$k]) || ($old && json_decode(Crypt::decryptString($old->contact_encrypted), true) != $contact);
            $material = $material || ($old && json_decode($old->address_details ?? 'null', true) != json_decode($row['address_details'] ?? 'null', true));
            if ($material) {
                $row += ['review_status' => 'draft', 'reviewed_by' => null, 'reviewed_at' => null, 'review_reference' => null, 'requested_at' => null];
            }
            $identity = $id ?? (string) Str::uuid();
            if ($old) {
                DB::table('merchant_branches')->where('id', $id)->update($row);
            } else {
                DB::table('merchant_branches')->insert([...$row, 'id' => $identity, 'merchant_id' => $m->id, 'code' => 'BR-'.strtoupper(substr(str_replace('-', '', $identity), 0, 8)), 'request_id' => $v['request_id'], 'request_hash' => $hash, 'created_at' => now()]);
            }
            $saved = $this->registry()->branch($m->id, $identity);
            $this->registry()->event($m->id, $identity, null, $old ? 'branch_updated' : 'branch_created', (string) $r->attributes->get('api_actor')->id, ['before' => $old ? $this->registry()->branchRow($old) : null, 'after' => $this->registry()->branchRow($saved), 'reason' => $v['reason']]);

            return response()->json(['data' => $this->registry()->branchRow($saved)], $old ? 200 : 201);
        });
    }

    public function terminalStore(Request $r)
    {
        return $this->saveTerminal($r, null);
    }

    public function terminalUpdate(Request $r, string $id)
    {
        return $this->saveTerminal($r, $id);
    }

    private function saveTerminal(Request $r, ?string $id)
    {
        $m = $this->member($r);
        $v = $r->validate(['branch_id' => 'required|uuid', 'identifier' => 'required|string|min:2|max:100', 'name' => 'required|string|min:2|max:160', 'pos_type' => 'required|in:pos,mobile_pos,web,erp,api', 'status' => 'required|in:active,inactive', 'api_key_id' => 'nullable|string|max:80', 'taxpayer_device_id' => 'nullable|uuid', 'reason' => 'required|string|min:10|max:1000', ...($id ? ['revision' => 'required|integer|min:1'] : ['request_id' => 'required|uuid']), 'merchant_id' => 'prohibited', 'issuance_ready' => 'prohibited', 'connection_status' => 'prohibited', 'sync_status' => 'prohibited']);

        return DB::transaction(function () use ($r, $m, $v, $id) {
            DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
            $b = $this->registry()->branch($m->id, $v['branch_id']);
            $old = $id ? $this->registry()->terminal($m->id, $id) : null;
            $hash = hash('sha256', json_encode($v));
            if (! $old && ($same = DB::table('merchant_terminals')->where('merchant_id', $m->id)->where('request_id', $v['request_id'])->first())) {
                abort_unless(hash_equals($same->request_hash, $hash), 409, 'Request ID was used for different terminal details.');

                return response()->json(['data' => ['id' => $same->id]]);
            }
            if ($old) {
                abort_unless($old->revision === (int) $v['revision'], 409, 'Terminal changed. Refresh before saving.');
                abort_unless($old->identifier === $v['identifier'], 422, 'Preserve the registered terminal identifier; create a new record for another terminal.');
            }
            abort_if(DB::table('merchant_terminals')->where('merchant_id', $m->id)->where('identifier', $v['identifier'])->when($old, fn ($q) => $q->where('id', '!=', $old->id))->exists(), 422, 'Terminal identifier is already registered for this business.');
            $this->registry()->currentKey($v['api_key_id'] ?? null, $m->id, (string) $b->bir_branch_code);
            $this->registry()->currentDevice($v['taxpayer_device_id'] ?? null, $m->id, (string) $b->bir_branch_code);
            $row = array_intersect_key($v, array_flip(['branch_id', 'identifier', 'name', 'pos_type', 'status', 'api_key_id', 'taxpayer_device_id']));
            $row += ['api_key_id' => null, 'taxpayer_device_id' => null, 'revision' => $old ? $old->revision + 1 : 1, 'updated_at' => now()];
            $identity = $id ?? (string) Str::uuid();
            if ($old) {
                DB::table('merchant_terminals')->where('id', $id)->update($row);
            } else {
                DB::table('merchant_terminals')->insert([...$row, 'id' => $identity, 'merchant_id' => $m->id, 'code' => 'TRM-'.strtoupper(substr(str_replace('-', '', $identity), 0, 8)), 'request_id' => $v['request_id'], 'request_hash' => $hash, 'created_at' => now()]);
            }
            $this->registry()->event($m->id, $b->id, $identity, $old ? 'terminal_updated' : 'terminal_registered', (string) $r->attributes->get('api_actor')->id, ['before' => $old ? (array) $old : null, 'after' => $row, 'reason' => $v['reason']]);

            return response()->json(['data' => ['id' => $identity]], $old ? 200 : 201);
        });
    }

    public function upload(Request $r, string $id)
    {
        $m = $this->member($r);
        $this->registry()->branch($m->id, $id);
        $v = $r->validate(['kind' => ['required', Rule::in(array_keys(Registry::DOCUMENTS))], 'file' => 'required|file|max:3072|mimes:pdf,png,jpg,jpeg', 'reason' => 'required|string|min:10|max:1000']);
        $f = $r->file('file');
        $raw = file_get_contents($f->getRealPath());
        $sha = hash('sha256', $raw);
        $docId = (string) Str::uuid();
        $path = 'branch-evidence/'.$m->id.'/'.$docId.'.enc';
        try {
            return DB::transaction(function () use ($r, $m, $id, $v, $f, $raw, $sha, $docId, $path) {
                DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
                $b = $this->registry()->branch($m->id, $id);
                $same = DB::table('merchant_branch_documents')->where('branch_id', $id)->where('kind', $v['kind'])->where('sha256', $sha)->first();
                if ($same) {
                    return response()->json(['id' => $same->id]);
                }abort_unless(Storage::disk('local')->put($path, Crypt::encryptString(base64_encode($raw))), 503, 'Evidence storage is unavailable. No document was recorded.');
                DB::table('merchant_branch_documents')->insert(['id' => $docId, 'merchant_id' => $m->id, 'branch_id' => $id, 'kind' => $v['kind'], 'filename_encrypted' => Crypt::encryptString(basename($f->getClientOriginalName())), 'mime' => $f->getMimeType(), 'bytes' => strlen($raw), 'sha256' => $sha, 'private_path' => $path, 'actor_id' => (string) $r->attributes->get('api_actor')->id, 'created_at' => now()]);
                DB::table('merchant_branches')->where('id', $id)->update(['review_status' => 'draft', 'reviewed_by' => null, 'reviewed_at' => null, 'review_reference' => null, 'requested_at' => null, 'revision' => $b->revision + 1, 'updated_at' => now()]);
                $this->registry()->event($m->id, $id, null, 'document_uploaded', (string) $r->attributes->get('api_actor')->id, ['document_id' => $docId, 'kind' => $v['kind'], 'sha256' => $sha, 'reason' => $v['reason']]);

                return response()->json(['id' => $docId], 201);
            });
        } catch (\Throwable$e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function download(Request $r, string $id)
    {
        $admin = $r->is('api/admin/branch-documents/*');
        if ($admin) {
            app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
        }$m = $admin ? null : $this->member($r);
        $d = DB::table('merchant_branch_documents')->when(! $admin, fn ($q) => $q->where('merchant_id', $m->id))->where('id', $id)->first();
        abort_unless($d, 404, 'Document not found.');
        $raw = base64_decode(Crypt::decryptString(Storage::disk('local')->get($d->private_path)), true);
        abort_unless($raw !== false && hash_equals($d->sha256, hash('sha256', $raw)), 409, 'Document integrity verification failed.');

        return response($raw)->header('Content-Type', $d->mime)->header('Content-Disposition', 'attachment; filename="NUERS-branch-evidence-'.substr($d->id, 0, 8).($d->mime === 'application/pdf' ? '.pdf' : ($d->mime === 'image/png' ? '.png' : '.jpg')).'"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function requestReview(Request $r, string $id)
    {
        $m = $this->member($r);
        $v = $r->validate(['revision' => 'required|integer|min:1', 'reason' => 'required|string|min:10|max:1000']);

        return DB::transaction(function () use ($r, $m, $v, $id) {
            DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
            $b = $this->registry()->branch($m->id, $id);
            abort_unless($b->revision === (int) $v['revision'], 409, 'Branch changed. Refresh before requesting review.');
            $contact = $this->registry()->branchRow($b);
            abort_unless($b->bir_branch_code && $contact['manager_name'] && $contact['phone'], 422, 'Record the five-digit BIR branch code, contact person and phone before review.');
            $kinds = DB::table('merchant_branch_documents')->where('branch_id', $id)->pluck('kind')->unique()->all();
            abort_unless(count(array_intersect($kinds, array_keys(Registry::DOCUMENTS))) === count(Registry::DOCUMENTS), 422, 'Upload all five branch evidence categories before requesting review.');
            abort_if($b->review_status === 'pending_review', 409, 'This branch is already awaiting administrator review.');
            DB::table('merchant_branches')->where('id', $id)->update(['review_status' => 'pending_review', 'requested_at' => now(), 'revision' => $b->revision + 1, 'updated_at' => now()]);
            $this->registry()->event($m->id, $id, null, 'verification_requested', (string) $r->attributes->get('api_actor')->id, ['reason' => $v['reason']]);

            return response()->json(['message' => 'Branch evidence submitted for independent administrator review. BIR authority remains separate.']);
        });
    }

    public function pending(Request $r)
    {
        app(ApiAccess::class)->require($r, ['admin', 'super_admin']);

        return response()->json(['data' => DB::table('merchant_branches')->where('review_status', 'pending_review')->select('id', 'merchant_id', 'code', 'name', 'bir_branch_code', 'city', 'revision', 'requested_at')->orderBy('requested_at')->paginate(25)])->header('Cache-Control', 'private, no-store');
    }

    public function reviewDetail(Request $r, string $id)
    {
        app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
        $b = DB::table('merchant_branches')->where('id', $id)->first();
        abort_unless($b, 404);

        return response()->json(['branch' => $this->registry()->branchRow($b), 'documents' => DB::table('merchant_branch_documents')->where('branch_id', $id)->orderByDesc('created_at')->get()->map(fn ($d) => ['id' => $d->id, 'kind' => $d->kind, 'name' => Crypt::decryptString($d->filename_encrypted), 'bytes' => $d->bytes]), 'readiness' => $this->registry()->readiness($b)])->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $r, string $id)
    {
        $actor = app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
        $v = $r->validate(['revision' => 'required|integer|min:1', 'decision' => 'required|in:verified,needs_changes', 'reference' => 'required|string|min:8|max:500', 'reason' => 'required|string|min:10|max:1000']);

        return DB::transaction(function () use ($actor, $v, $id) {
            $b = DB::table('merchant_branches')->where('id', $id)->first();
            abort_unless($b, 404);
            DB::table('merchants')->where('id', $b->merchant_id)->lockForUpdate()->first();
            $b = DB::table('merchant_branches')->where('id', $id)->lockForUpdate()->first();
            abort_if((string) DB::table('merchants')->where('id', $b->merchant_id)->value('merchant_account_id') === (string) $actor->id, 403, 'Branch evidence requires independent review.');
            abort_unless($b->revision === (int) $v['revision'] && $b->review_status === 'pending_review', 409, 'Review is stale or not pending.');
            DB::table('merchant_branches')->where('id', $id)->update(['review_status' => $v['decision'], 'review_reference' => $v['reference'], 'reviewed_by' => (string) $actor->id, 'reviewed_at' => now(), 'revision' => $b->revision + 1, 'updated_at' => now()]);
            $this->registry()->event($b->merchant_id, $id, null, 'branch_reviewed', (string) $actor->id, $v);

            return response()->json(['message' => 'NUERS branch review recorded. This does not grant BIR issuance or transmission authority.']);
        });
    }

    public function export(Request $r)
    {
        $m = $this->member($r);
        $v = $this->filters($r);
        $terminal = $r->query('registry') === 'terminals';
        $q = $terminal ? $this->terminalQuery($m->id, $v) : $this->branches($m->id, $v);
        if ($r->has('report_limit')) {
            \App\Services\ReportCsv::limit($r, (clone $q)->count());
        }
        abort_if((clone $q)->count() > 5000, 422, 'Narrow the export filters to 5,000 records or fewer.');
        $name = $terminal ? 'terminals' : 'branches';

        return response()->streamDownload(function () use ($q, $terminal) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, $terminal ? ['Terminal code', 'Identifier', 'Name', 'Branch ID', 'POS type', 'Operational status'] : ['Branch code', 'Name', 'BIR branch code', 'Address', 'City', 'Region', 'RDO', 'Type', 'Operational status', 'NUERS review'], ',', '"', '');
            foreach ($q->orderBy('id')->cursor() as $r) {
                $values = $terminal ? [$r->code, $r->identifier, $r->name, $r->branch_id, $r->pos_type, $r->status] : [$r->code, $r->name, $r->bir_branch_code, $r->address, $r->city, $r->region, $r->rdo_code, $r->branch_type, $r->status, $r->review_status];
                fputcsv($f, array_map(fn ($s) => preg_match('/^[=+@\-\t\r\n]/u', (string) $s) ? "'".$s : (string) $s, $values), ',', '"', '');
            }fclose($f);
        }, 'NUERS-'.$name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
