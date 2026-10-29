<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Profile;
use App\Models\User;
use App\Services\BirCompliance;
use App\Services\RegistrationAddress;
use App\Services\RegistrationIndustry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class BusinessRegistryController extends Controller
{
    const CSV = ['business_name', 'tin', 'owner_name', 'email', 'phone', 'business_type', 'sector', 'address', 'city', 'region', 'rdo_code', 'vat_registered', 'bir_registration_date'];

    private function actor(Request $r, bool $write = false)
    {
        $u = app(BirCompliance::class)->administrativeUser($r);
        if ($write) {
            abort_unless(in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin', 'super_admin']), 403);
        }

        return $u;
    }

    private function lock(): void
    {
        DB::table('business_registry_lock')->where('id', 1)->lockForUpdate()->first();
    }

    private function audit(string $action, string $id, $u, array $data = []): void
    {
        app(BirCompliance::class)->audit('business_registry.'.$action, $id, (string) $u->id, $data);
    }

    private function tin(string $v): string
    {
        $s = preg_replace('/\D/', '', $v);

        return strlen($s) === 9 ? $s.'000' : $s;
    }

    private function duplicate(string $tin, ?string $except = null): bool
    {
        $digits = $this->tin($tin);
        $q = DB::table('merchants')->whereIn(DB::raw("REPLACE(REPLACE(tin,'-',''),' ','')"), str_ends_with($digits, '000') ? [$digits, substr($digits, 0, 9)] : [$digits]);
        if ($except) {
            $q->where('id', '<>', $except);
        }

        return $q->exists();
    }

    private function query(Request $r)
    {
        $v = $r->validate(['search' => 'nullable|string|max:200', 'type' => 'nullable|string|max:120', 'status' => ['nullable', Rule::in(['all', 'active', 'pending', 'suspended', 'inactive'])], 'eis' => ['nullable', Rule::in(['all', 'certification', 'pending', 'error', 'not_connected'])], 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'rdo' => 'nullable|string|max:20', 'region' => 'nullable|string|max:150', 'city' => 'nullable|string|max:150', 'industry' => 'nullable|string|max:120', 'vat' => ['nullable', Rule::in(['all', 'yes', 'no'])], 'account' => ['nullable', Rule::in(['all', 'linked', 'unlinked'])], 'stage' => ['nullable', Rule::in(['all', 'evaluation', 'bir_validation', 'eis_setup', 'onboarded', 'rejected'])], 'application_type' => ['nullable', Rule::in(['all', 'new', 'migration'])], 'sort' => ['nullable', Rule::in(['newest', 'oldest', 'name'])], 'per_page' => 'nullable|integer|min:1|max:100', 'page' => 'nullable|integer|min:1']);
        if (! empty($v['from']) && ! empty($v['to']) && $v['from'] > $v['to']) {
            abort(422, 'End date must follow the start date.');
        }$q = DB::table('merchants as m');
        if (! empty($v['search'])) {
            $s = '%'.str_replace(['%', '_'], ['\%', '\_'], $v['search']).'%';
            $q->where(fn ($x) => $x->where('m.business_name', 'like', $s)->orWhere('m.tin', 'like', $s)->orWhere('m.email', 'like', $s)->orWhere('m.owner_name', 'like', $s)->orWhere('m.address', 'like', $s));
        }foreach (['type' => 'business_type', 'status' => 'status', 'rdo' => 'rdo_code', 'region' => 'region', 'city' => 'city', 'industry' => 'sector'] as $k => $c) {
            if (! empty($v[$k]) && $v[$k] !== 'all') {
                $q->where('m.'.$c, $v[$k]);
            }
        }if (($v['vat'] ?? 'all') !== 'all') {
            $q->where('m.vat_registered', $v['vat'] === 'yes');
        }if (($v['account'] ?? 'all') === 'linked') {
            $q->whereNotNull('m.merchant_account_id');
        }if (($v['account'] ?? 'all') === 'unlinked') {
            $q->whereNull('m.merchant_account_id');
        }if (! empty($v['from'])) {
            $q->whereRaw('COALESCE(m.registration_date, DATE(m.created_at)) >= ?', [$v['from']]);
        }if (! empty($v['to'])) {
            $q->whereRaw('COALESCE(m.registration_date, DATE(m.created_at)) <= ?', [$v['to']]);
        }

        return [$q, $v];
    }

    private function present($m, bool $loadIntegration = true): array
    {
        $x = (array) $m;
        $meta = json_decode($x['registry_details'] ?? '{}', true) ?: [];
        $e = $loadIntegration ? DB::table('eis_integrations')->where('merchant_id', $m->id)->orderByDesc('updated_at')->orderByDesc('id')->first() : null;
        $eis = ! $e ? 'not_connected' : ($e->environment !== 'production' ? 'certification' : (in_array($e->status, ['error', 'failed', 'rejected']) ? 'error' : 'pending'));
        $x['details'] = $meta;
        $x['eis_status'] = $x['registry_eis_status'] ?? $eis;
        $x['eis_environment'] = $e?->environment;
        $x['integration'] = $e ? ['id' => $e->id, 'environment' => $e->environment, 'status' => $e->status, 'certification_status' => $e->certification_status, 'ptt_status' => $e->ptt_status, 'permit_to_transmit_number' => $e->permit_to_transmit_number, 'eis_certificate_number' => $e->eis_certificate_number] : null;
        $x['stage'] = $meta['stage'] ?? ($m->status === 'active' ? 'onboarded' : 'evaluation');
        $x['application_type'] = $meta['application_type'] ?? 'new';
        $x['vat_registered'] = (bool) $m->vat_registered;
        $x['account_linked'] = (bool) $m->merchant_account_id;
        $x['registry_revision'] = (int) $m->registry_revision;
        unset($x['registry_details'], $x['registry_eis_status']);
        foreach (['created_at', 'updated_at'] as $k) {
            if ($x[$k] ?? null) {
                $x[$k] = Carbon::parse($x[$k], config('app.timezone'))->toIso8601String();
            }
        }

        return $x;
    }

    private function metadata(string $field): string
    {
        $json = "CASE WHEN JSON_VALID(m.registry_details) THEN m.registry_details ELSE '{}' END";
        $value = DB::getDriverName() === 'mysql' ? "NULLIF(JSON_UNQUOTE(JSON_EXTRACT($json, '$.$field')), 'null')" : "JSON_EXTRACT($json, '$.$field')";
        $fallback = $field === 'stage' ? "CASE WHEN m.status='active' THEN 'onboarded' ELSE 'evaluation' END" : "'new'";
        return "COALESCE($value, $fallback)";
    }

    private function filteredQuery(Request $r): array
    {
        [$q, $v] = $this->query($r);
        foreach (['stage', 'application_type'] as $field) {
            if (($v[$field] ?? 'all') !== 'all') $q->whereRaw($this->metadata($field).' = ?', [$v[$field]]);
        }
        $eis = "COALESCE((SELECT CASE WHEN ei.environment<>'production' THEN 'certification' WHEN ei.status IN ('error','failed','rejected') THEN 'error' ELSE 'pending' END FROM eis_integrations ei WHERE ei.merchant_id=m.id ORDER BY ei.updated_at DESC,ei.id DESC LIMIT 1),'not_connected')";
        if (($v['eis'] ?? 'all') !== 'all') $q->whereRaw("$eis = ?", [$v['eis']]);
        return [$q, $v, $eis];
    }

    private function ordered($q, array $v)
    {
        return match ($v['sort'] ?? 'newest') {
            'name' => $q->orderBy('m.business_name')->orderBy('m.id'),
            'oldest' => $q->orderBy('m.created_at')->orderBy('m.id'),
            default => $q->orderByDesc('m.created_at')->orderBy('m.id'),
        };
    }

    public function index(Request $r)
    {
        $u = $this->actor($r);
        [$q, $v, $eis] = $this->filteredQuery($r);
        $statuses = (clone $q)->select('m.status')->selectRaw('COUNT(*) as total')->groupBy('m.status')->pluck('total', 'status');
        $stageExpression = $this->metadata('stage');
        // A derived column keeps MariaDB's ONLY_FULL_GROUP_BY validation independent
        // of the JSON expression and its status fallback.
        $stages = DB::query()->fromSub((clone $q)->selectRaw("$stageExpression as registry_stage"), 'registry_stages')
            ->select('registry_stage')->selectRaw('COUNT(*) as total')->groupBy('registry_stage')->pluck('total', 'registry_stage');
        $summary = ['total' => (int) $statuses->sum(), 'active' => (int) ($statuses['active'] ?? 0), 'pending' => (int) ($statuses['pending'] ?? 0),
            'suspended' => (int) ($statuses['suspended'] ?? 0) + (int) ($statuses['inactive'] ?? 0), 'connected' => 0];
        foreach (['evaluation', 'bir_validation', 'eis_setup', 'onboarded', 'rejected'] as $stage) {
            $summary[$stage] = (int) ($stages[$stage] ?? 0);
        }
        $size = (int) ($v['per_page'] ?? 10);
        $lastPage = max(1, (int) ceil($summary['total'] / $size));
        $page = min($lastPage, (int) ($v['page'] ?? 1));
        $rows = $this->ordered((clone $q)->select('m.*')->selectRaw("$eis as registry_eis_status"), $v)
            ->forPage($page, $size)->get()->map(fn ($m) => $this->present($m, false));
        $canWrite = in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin', 'super_admin']);
        $overview = app(\App\Services\RegistryMerchantOverview::class);
        $finances = $canWrite ? $overview->rows($rows->pluck('id')->all()) : [];
        $rows = $rows->map(fn ($row) => $row + ['overview' => $finances[$row['id']] ?? null]);
        return response()->json(['data' => $rows, 'summary' => $summary, 'financial_summary' => $canWrite ? $overview->summary($q) : null,
            'total' => $summary['total'], 'page' => $page, 'last_page' => $lastPage,
            'charts' => ['registration' => $statuses->map(fn ($n, $name) => ['name' => $name, 'value' => (int) $n])->values(), 'eis' => []],
            'options' => ['types' => BusinessRegistrationController::BUSINESS_TYPES, 'industries' => app(RegistrationIndustry::class)->options(), 'rdos' => app(RegistrationAddress::class)->offices(),
                'regions' => DB::table('merchants')->distinct()->pluck('region'), 'cities' => DB::table('merchants')->whereNotNull('city')->distinct()->pluck('city')],
            'can_write' => $canWrite, 'can_view_financial' => $canWrite && $overview->available(), 'generated_at' => now()->toIso8601String(), 'gateway' => 'not_verified'])
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, string $id)
    {
        $u = $this->actor($r);
        $m = DB::table('merchants')->where('id', $id)->first();
        abort_unless($m, 404);
        $x = $this->present($m);
        $x['history'] = DB::table('eis_audit_events')->where('subject_id', $id)->orderByDesc('id')->limit(30)->get(['event', 'actor_id', 'occurred_at', 'details']);
        $x['documents'] = DB::table('transaction_receipts')->where('merchant_id', $id)->count();
        $x['verification_documents'] = app(\App\Services\MerchantVerificationDocuments::class)->present($id)['uploaded'];
        $x['transactions'] = DB::table('merchant_transactions')->where('merchant_id', $id)->count();
        $account = $m->merchant_account_id ? DB::table('users')->where('id', $m->merchant_account_id)->first(['email', 'email_verified_at', 'created_at']) : null;
        $x['account'] = $account;
        if (in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin', 'super_admin'])) {
            $x['overview'] = app(\App\Services\RegistryMerchantOverview::class)->rows([$id])[$id] ?? null;
        }

        return response()->json(['business' => $x]);
    }

    private function validated(array $data, ?string $id = null): array
    {
        abort_unless(preg_match('/^[0-9\s-]+$/', (string) ($data['tin'] ?? '')), 422, 'TIN must contain digits, spaces or hyphens only.');
        $data['tin'] = $this->tin($data['tin'] ?? '');
        $data['email'] = strtolower(trim($data['email'] ?? ''));
        $r = Validator::make($data, ['tin' => 'required|digits:12', 'business_name' => 'required|string|max:255', 'owner_name' => 'required|string|max:255', 'email' => 'required|email|max:255', 'phone' => 'nullable|string|max:80', 'business_type' => ['required', Rule::in(array_keys(BusinessRegistrationController::BUSINESS_TYPES))], 'sector' => 'required|string|max:120', 'industry_other' => 'nullable|string|max:255', 'address' => 'required|string|max:1000', 'city' => 'required|string|max:255', 'region' => 'required|string|max:150', 'rdo_code' => ['required', Rule::in(array_column(app(RegistrationAddress::class)->offices(), 'rdo_code'))], 'vat_registered' => 'required|boolean', 'bir_registration_date' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'website' => 'nullable|url:http,https|max:255', 'notes' => 'nullable|string|max:3000', 'application_type' => ['nullable', Rule::in(['new', 'migration'])], 'structured_address' => 'nullable|array'])->validate();
        abort_if($r['tin'] === '000000000000', 422, 'TIN cannot be all zeros.');
        if ($this->duplicate($r['tin'], $id)) {
            throw ValidationException::withMessages(['tin' => 'This TIN and branch are already in the registry.']);
        }$industry = app(RegistrationIndustry::class)->options();
        abort_unless(isset($industry[$r['sector']]) || isset(BusinessRegistrationController::INDUSTRIES[$r['sector']]) || ($id && DB::table('merchants')->where('id', $id)->value('sector') === $r['sector']), 422, 'Select an industry from the catalogue.');
        if ($r['sector'] === 'other') {
            abort_if(empty($r['industry_other']), 422, 'Describe the other business activity.');
        }$office = collect(app(RegistrationAddress::class)->offices())->firstWhere('rdo_code', $r['rdo_code']);
        $meta = ['industry' => app(RegistrationIndustry::class)->snapshot($r['sector'], $r['industry_other'] ?? null), 'application_type' => $r['application_type'] ?? 'new'];
        if (! empty($r['structured_address'])) {
            $a = $r['structured_address'] + ['rdo_code' => $r['rdo_code']];
            Validator::make($a, ['region_code' => 'required|digits:10', 'province_code' => 'required|digits:10', 'city_code' => 'required|digits:10', 'barangay_code' => 'required|digits:10', 'street_address' => 'required|string|max:500', 'zip_code' => 'required|digits:4'])->validate();
            $address = app(RegistrationAddress::class)->validate($a);
            $r['address'] = $address['address'];
            $r['city'] = $address['details']['city']['name'];
            $r['region'] = $address['details']['region']['name'];
            $r['city_code'] = $a['city_code'];
            $r['barangay_code'] = $a['barangay_code'];
            $r['barangay'] = $address['details']['barangay']['name'];
            $r['zip_code'] = $a['zip_code'];
            $meta['address'] = $address['details'];
        }unset($r['industry_other'],$r['application_type'],$r['structured_address']);
        $r['business_registration_address'] = $r['address'];
        $r['rdo_name'] = $office['rdo_name'];
        $r['country'] = 'Philippines';
        $r['country_code'] = 'PH';

        return [$r, $meta];
    }

    public function store(Request $r)
    {
        $u = $this->actor($r, true);
        $r->validate(['request_id' => 'required|uuid']);

        return DB::transaction(function () use ($r, $u) {
            $this->lock();
            $hash = hash('sha256', json_encode($r->except('request_id')));
            $old = DB::table('business_registry_requests')->where('id', $r->input('request_id'))->first();
            if ($old) {
                abort_unless($old->actor_id == (string) $u->id && $old->purpose === 'create' && hash_equals($old->request_hash, $hash), 409, 'Request identifier already used for different data.');

                return response()->json(['business' => $this->present(DB::table('merchants')->where('id', $old->merchant_id)->first()), 'duplicate' => true]);
            }[$v,$meta] = $this->validated($r->all());
            $m = Merchant::create($v + ['status' => 'pending', 'registration_date' => now()->toDateString(), 'registry_details' => json_encode($meta + ['stage' => 'evaluation'])]);
            DB::table('business_registry_requests')->insert(['id' => $r->input('request_id'), 'actor_id' => $u->id, 'purpose' => 'create', 'merchant_id' => $m->id, 'request_hash' => $hash, 'payload' => Crypt::encryptString('{}'), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('created', $m->id, $u, ['fields' => $v]);

            return response()->json(['business' => $this->present(DB::table('merchants')->where('id', $m->id)->first())], 201);
        });
    }

    public function update(Request $r, string $id)
    {
        $u = $this->actor($r, true);
        $r->validate(['revision' => 'required|integer|min:1', 'reason' => 'required|string|min:10|max:2000']);

        return DB::transaction(function () use ($r, $u, $id) {
            $this->lock();
            $old = DB::table('merchants')->where('id', $id)->lockForUpdate()->first();
            abort_unless($old, 404);
            abort_unless($old->registry_revision === $r->integer('revision'), 409, 'Business changed. Refresh before saving.');
            [$v,$meta] = $this->validated($r->all(), $id);
            $meta = array_replace(json_decode($old->registry_details ?? '{}', true) ?: [], $meta);
            DB::table('merchants')->where('id', $id)->update($v + ['registry_revision' => $old->registry_revision + 1, 'registry_details' => json_encode($meta), 'updated_at' => now()]);
            $identityChanged=false;
            foreach(['business_name','tin','vat_registered','address','rdo_code','branch_count'] as $field) if(array_key_exists($field,$v) && (string)($v[$field] ?? '')!==(string)($old->$field ?? '')) $identityChanged=true;
            if($identityChanged) DB::table('eis_integrations')->where('merchant_id',$id)->update(['pti_status'=>'pending','ptt_status'=>'pending','certification_status'=>'pending','updated_at'=>now()]);
            $this->audit('updated', $id, $u, ['reason' => $r->input('reason'), 'before' => array_intersect_key((array) $old, $v), 'after' => $v]);

            return response()->json(['business' => $this->present(DB::table('merchants')->where('id', $id)->first())]);
        });
    }

    public function review(Request $r, string $id)
    {
        $u = $this->actor($r, true);
        $v = $r->validate(['revision' => 'required|integer|min:1', 'reason' => 'required|string|min:10|max:2000', 'status' => ['nullable', Rule::in(['pending', 'active', 'suspended', 'inactive'])], 'stage' => ['nullable', Rule::in(['evaluation', 'bir_validation', 'eis_setup', 'onboarded', 'rejected'])], 'checks' => 'nullable|array', 'checks.*' => 'boolean', 'evidence' => 'nullable|string|max:2000']);

        return DB::transaction(function () use ($v, $id, $u) {
            $this->lock();
            $m = DB::table('merchants')->where('id', $id)->lockForUpdate()->first();
            abort_unless($m, 404);
            abort_unless($m->registry_revision === $v['revision'], 409, 'Business review changed. Refresh first.');
            $meta = json_decode($m->registry_details ?? '{}', true) ?: [];
            $meta['checks'] = $v['checks'] ?? ($meta['checks'] ?? []);
            $stage = $v['stage'] ?? ($meta['stage'] ?? ($m->status === 'active' ? 'onboarded' : 'evaluation'));
            $status = $v['status'] ?? $m->status;
            if ($stage === 'onboarded' && ! in_array($status, ['suspended', 'inactive'])) {
                foreach (['registration_certificate', 'identity', 'business_information', 'representative_authority', 'contact_verification'] as $key) {
                    abort_unless($meta['checks'][$key] ?? false, 422, 'Complete all five document-review checks before onboarding.');
                }abort_unless(! empty($v['evidence']), 422, 'An evidence/reference is required before onboarding.');
                app(\App\Services\MerchantVerificationDocuments::class)->assertActivation($id);
                $status = 'active';
            }if ($stage === 'rejected') {
                $status = 'inactive';
            }if ($status === 'active' && $stage !== 'onboarded') {
                abort(422, 'Complete the onboarding review before activation.');
            }$meta['stage'] = $stage;
            $meta['evidence'] = $v['evidence'] ?? ($meta['evidence'] ?? null);
            DB::table('merchants')->where('id', $id)->update(['status' => $status, 'registry_details' => json_encode($meta), 'registry_revision' => $v['revision'] + 1, 'updated_at' => now()]);
            if ($status === 'active') {
                app(\App\Services\MerchantApiOnboarding::class)->prepare(
                    DB::table('merchants')->where('id', $id)->first(), (string) $u->id, true
                );
            }
            if (in_array($status, ['suspended', 'inactive']) && $m->merchant_account_id) {
                abort_unless(DB::table('profiles')->where('id', $m->merchant_account_id)->value('role') === 'merchant', 409, 'Linked account must be reviewed before changing access.');
                DB::table('users')->where('id', $m->merchant_account_id)->update(app(\App\Services\ApiAccess::class)->revocation());
            }DB::table('business_registry_requests')->where('merchant_id', $id)->where('purpose', 'invitation')->whereNull('completed_at')->update(['revoked_at' => now()]);
            $this->audit('reviewed', $id, $u, ['reason' => $v['reason'], 'before_status' => $m->status, 'status' => $status, 'stage' => $stage, 'checks' => $meta['checks'], 'evidence' => $meta['evidence']]);

            return response()->json(['business' => $this->present(DB::table('merchants')->where('id', $id)->first())]);
        });
    }

    public function validateTin(Request $r)
    {
        $this->actor($r);
        $r->validate(['tin' => 'required|string|max:30']);
        $tin = $this->tin($r->input('tin'));
        $valid = preg_match('/^[0-9\s-]+$/', $r->input('tin')) && strlen($tin) === 12 && $tin !== '000000000000';

        return response()->json(['normalized' => $tin, 'format_valid' => (bool) $valid, 'in_registry' => $valid ? $this->duplicate($tin) : false, 'bir_verified' => false, 'message' => 'Local format and NUERS registry check only. No authenticated BIR TIN verification service is configured.']);
    }

    public function export(Request $r)
    {
        $u = $this->actor($r);
        [$q, $v] = $this->filteredQuery($r);
        $total = (clone $q)->count();
        if ($r->has('report_limit')) \App\Services\ReportCsv::limit($r,$total);
        abort_if($total > 10000, 422, 'Narrow the registry filters to at most 10,000 merchants per export.');
        $canFinancial = in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin', 'super_admin'])
            && app(\App\Services\RegistryMerchantOverview::class)->available();
        $this->audit('exported', 'registry', $u, ['filters' => $r->query(), 'records' => $total]);

        return response()->streamDownload(function () use ($q, $v, $canFinancial) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            $keys = array_merge(self::CSV, ['status', 'registration_date', 'stage', 'application_type']);
            $financialKeys = ['available_centavos', 'reserved_centavos', 'transactions', 'transaction_amount_centavos',
                'successful_transactions', 'failed_transactions', 'pending_transactions', 'charged_centavos', 'charged_transactions',
                'deposits_centavos', 'withdrawals_centavos', 'pending_topups', 'pending_topups_centavos',
                'live_keys', 'sandbox_keys', 'sandbox_events', 'last_transaction_at'];
            fputcsv($f, array_merge($keys, $canFinancial ? array_map(fn ($k) => str_replace('_centavos', '_PHP', $k), $financialKeys) : []));
            $this->ordered((clone $q)->select('m.*'), $v)->chunk(200, function ($merchants) use ($f, $keys, $financialKeys, $canFinancial) {
                $financial = $canFinancial ? app(\App\Services\RegistryMerchantOverview::class)->rows($merchants->pluck('id')->all()) : [];
                foreach ($merchants as $merchant) {
                    $row = $this->present($merchant, false);
                    $cells = array_map(fn ($k) => $this->cell($row[$k] ?? ''), $keys);
                    if ($canFinancial) foreach ($financialKeys as $key) {
                        $value = $financial[$merchant->id][$key] ?? '';
                        $cells[] = str_ends_with($key, '_centavos') ? number_format((int) $value / 100, 2, '.', '') : $value;
                    }
                    fputcsv($f, $cells);
                }
            });
            fclose($f);
        }, 'NUERS-business-registry-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function cell($v)
    {
        if (is_bool($v)) {
            return (int) $v;
        }

        return is_string($v) && preg_match('/^\s*[=+@\-]/', $v) ? "'".$v : $v;
    }

    public function template(Request $r)
    {
        $this->actor($r);

        return response()->streamDownload(function () {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, self::CSV);
            fclose($f);
        }, 'NUERS-business-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function importPreview(Request $r)
    {
        $u = $this->actor($r, true);
        $r->validate(['file' => 'required|file|max:2048', 'request_id' => 'required|uuid']);
        $raw = file_get_contents($r->file('file')->getRealPath());
        abort_unless(mb_check_encoding($raw, 'UTF-8'), 422, 'Use UTF-8 CSV encoding.');
        $f = fopen('php://temp', 'r+');
        fwrite($f, preg_replace('/^\xEF\xBB\xBF/', '', $raw));
        rewind($f);
        $header = fgetcsv($f);
        abort_unless($header === self::CSV, 422, 'CSV headers must match the downloadable template exactly.');
        $rows = [];
        $errors = [];
        $seen = [];
        $line = 1;
        while (($cells = fgetcsv($f)) !== false) {
            $line++;
            if ($cells === [null]) {
                continue;
            }abort_if(count($rows) >= 500, 422, 'Maximum 500 businesses per import.');
            if (count($cells) !== count($header)) {
                $errors[] = ['line' => $line, 'message' => 'Incorrect column count'];
                $rows[] = ['line' => $line, 'business_name' => 'Invalid row'];

                continue;
            }$v = array_combine($header, $cells);
            $v['vat_registered'] = match (strtolower($v['vat_registered'])) {
                '1','true','yes' => true,'0','false','no' => false,default => null
            };
            if ($v['bir_registration_date'] === '') {
                $v['bir_registration_date'] = null;
            }try {
                [$record,$meta] = $this->validated($v);
                abort_if(isset($seen[$record['tin']]), 422, 'TIN is repeated in this CSV.');
                $seen[$record['tin']] = true;
                $rows[] = ['line' => $line, 'record' => $record, 'meta' => $meta];
            } catch (\Throwable $e) {
                $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage();
                $errors[] = ['line' => $line, 'message' => $message];
                $rows[] = ['line' => $line, 'business_name' => $v['business_name'], 'error' => $message];
            }
        }fclose($f);
        abort_unless(count($rows), 422, 'CSV is empty.');
        $hash = hash('sha256', $raw);
        $payload = ['rows' => $rows, 'errors' => $errors, 'total' => count($rows), 'filename' => basename($r->file('file')->getClientOriginalName())];
        $id = $r->input('request_id');
        DB::transaction(function () use ($id, $u, $hash, $payload) {
            $this->lock();
            $old = DB::table('business_registry_requests')->where('id', $id)->first();
            if ($old) {
                abort_unless($old->actor_id == (string) $u->id && $old->purpose === 'import' && $old->request_hash === $hash, 409, 'Request identifier is already in use.');

                return;
            }DB::table('business_registry_requests')->insert(['id' => $id, 'actor_id' => $u->id, 'purpose' => 'import', 'request_hash' => $hash, 'payload' => Crypt::encryptString(json_encode($payload)), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['id' => $id, 'preview' => $payload, 'expires_in' => 3600]);
    }

    public function importCommit(Request $r, string $id)
    {
        $u = $this->actor($r, true);

        return DB::transaction(function () use ($id, $u) {
            $this->lock();
            $batch = DB::table('business_registry_requests')->where('id', $id)->where('purpose', 'import')->lockForUpdate()->first();
            abort_unless($batch && $batch->actor_id == (string) $u->id, 404);
            if ($batch->completed_at) {
                return response()->json(['imported' => json_decode(Crypt::decryptString($batch->payload), true)['total'], 'duplicate' => true]);
            }abort_if(Carbon::parse($batch->expires_at)->isPast(), 410, 'Import preview expired. Upload the CSV again.');
            $p = json_decode(Crypt::decryptString($batch->payload), true);
            abort_if(count($p['errors']), 422, 'Correct all CSV errors before importing.');
            $ids = [];
            foreach ($p['rows'] as $row) {
                [$v,$meta] = $this->validated($row['record']);
                $m = Merchant::create($v + ['status' => 'pending', 'registration_date' => now()->toDateString(), 'registry_details' => json_encode(array_replace($row['meta'], $meta, ['stage' => 'evaluation']))]);
                $ids[] = $m->id;
                $this->audit('imported', $m->id, $u, ['batch_id' => $id]);
            }DB::table('business_registry_requests')->where('id', $id)->update(['completed_at' => now(), 'updated_at' => now()]);
            $this->audit('import.completed', $id, $u, ['count' => count($ids), 'business_ids' => $ids]);

            return response()->json(['imported' => count($ids)]);
        });
    }

    public function invitation(Request $r, string $id)
    {
        $u = $this->actor($r, true);
        $r->validate(['request_id' => 'required|uuid', 'reason' => 'required|string|min:10|max:2000']);

        return DB::transaction(function () use ($r, $u, $id) {
            $this->lock();
            $m = DB::table('merchants')->where('id', $id)->lockForUpdate()->first();
            abort_unless($m, 404);
            abort_if($m->merchant_account_id, 409, 'An account is already linked. Use the existing account recovery process; credentials will not be overwritten.');
            abort_if(in_array($m->status, ['suspended', 'inactive']), 409, 'Restore the business before provisioning access.');
            abort_if(DB::table('users')->where('email', $m->email)->exists(), 409, 'Email belongs to an existing account. Review the account association first.');
            $old = DB::table('business_registry_requests')->where('id', $r->input('request_id'))->first();
            if ($old) {
                abort_unless($old->actor_id == (string) $u->id && $old->purpose === 'invitation' && $old->merchant_id === $id, 409);
                abort_if($old->completed_at || $old->revoked_at || Carbon::parse($old->expires_at)->isPast(), 410, 'This invitation is no longer usable. Generate a new one.');
                $p = json_decode(Crypt::decryptString($old->payload), true);

                return response()->json(['url' => url('/activate-business').'#token='.$p['token'], 'expires_at' => Carbon::parse($old->expires_at, config('app.timezone'))->toIso8601String()]);
            }abort_unless(filter_var($m->email, FILTER_VALIDATE_EMAIL), 422, 'Add a valid contact email first.');
            DB::table('business_registry_requests')->where('purpose', 'invitation')->where('merchant_id', $id)->whereNull('completed_at')->update(['revoked_at' => now()]);
            $token = Str::random(64);
            DB::table('business_registry_requests')->insert(['id' => $r->input('request_id'), 'actor_id' => $u->id, 'purpose' => 'invitation', 'merchant_id' => $id, 'request_hash' => hash('sha256', $m->email), 'token_hash' => hash('sha256', $token), 'payload' => Crypt::encryptString(json_encode(['token' => $token, 'email' => $m->email])), 'expires_at' => now()->addHours(48), 'created_at' => now(), 'updated_at' => now()]);
            app(\App\Services\PlatformNotifications::class)->queue($m->email,'business.invitation',(string)$r->input('request_id'),'Set up your NUERS business account','You have been invited to set up your NUERS business account. Use the secure link within 48 hours. This link works once. If you were not expecting this invitation, contact NUERS support.',action:app(\App\Services\PlatformNotifications::class)->url('activate-business').'#token='.$token,expires:now()->addHours(48),context:['invitation_id'=>$r->input('request_id')]);
            $this->audit('invitation.created', $id, $u, ['reason' => $r->input('reason'), 'expires_at' => now()->addHours(48)->toIso8601String()]);

            return response()->json(['url' => url('/activate-business').'#token='.$token, 'expires_at' => now()->addHours(48)->toIso8601String()]);
        });
    }

    public function activation(Request $r)
    {
        $v = $r->validate(['token' => 'required|string|size:64']);
        $x = DB::table('business_registry_requests')->where('purpose', 'invitation')->where('token_hash', hash('sha256', $v['token']))->first();
        abort_unless($x && ! $x->completed_at && ! $x->revoked_at && Carbon::parse($x->expires_at)->isFuture(), 410, 'Setup link is invalid, expired, revoked, or already used.');
        $m = DB::table('merchants')->where('id', $x->merchant_id)->first();
        abort_unless($m && ! in_array($m->status, ['suspended', 'inactive']) && ! $m->merchant_account_id, 410);
        $p = json_decode(Crypt::decryptString($x->payload), true);
        abort_unless($p['email'] === $m->email, 410, 'Business email changed. Request a new setup link.');

        return response()->json(['business_name' => $m->business_name, 'email_hint' => substr($m->email, 0, 1).'***@'.explode('@', $m->email)[1], 'notice_version' => BusinessRegistrationController::NOTICE_VERSION]);
    }

    public function activate(Request $r)
    {
        $this->activation($r);
        $v = $r->validate(['token' => 'required|string|size:64', 'password' => ['required', 'confirmed', 'string', 'max:72', Password::min(12)->letters()->numbers()], 'terms_accepted' => 'required|accepted', 'privacy_accepted' => 'required|accepted', 'notice_version' => ['required', Rule::in([BusinessRegistrationController::NOTICE_VERSION])]]);

        return DB::transaction(function () use ($v, $r) {
            $this->lock();
            $this->activation($r);
            $inv = DB::table('business_registry_requests')->where('token_hash', hash('sha256', $v['token']))->lockForUpdate()->first();
            $m = DB::table('merchants')->where('id', $inv->merchant_id)->lockForUpdate()->first();
            abort_if(DB::table('users')->where('email', $m->email)->exists(), 409, 'An account already exists. Contact support.');
            $u = User::create(['name' => $m->owner_name ?: $m->business_name, 'email' => $m->email, 'password' => Hash::make($v['password']), 'api_token' => null]);
            Profile::create(['id' => $u->id, 'email' => $u->email, 'role' => 'merchant', 'full_name' => $u->name, 'organization' => $m->business_name, 'tin' => $m->tin, 'tin_bound_at' => now()]);
            DB::table('merchants')->where('id', $m->id)->update(['merchant_account_id' => $u->id, 'merchant_account_email' => $u->email, 'registry_revision' => $m->registry_revision + 1, 'updated_at' => now()]);
            DB::table('business_registry_requests')->where('id', $inv->id)->update(['completed_at' => now(), 'payload' => Crypt::encryptString(json_encode(['email' => $m->email, 'consented_at' => now()->toIso8601String(), 'notice_version' => $v['notice_version']])), 'updated_at' => now()]);
            app(\App\Services\PlatformNotifications::class)->verification($u);
            $this->audit('account.activated', $m->id, $u, ['terms_version' => $v['notice_version'], 'privacy_version' => $v['notice_version'], 'email_verified' => false]);

            return response()->json(['message' => 'Account created. Sign in with your business contact email. Platform approval and BIR permissions remain separate.']);
        });
    }
}
