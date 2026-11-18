<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\Eis\InvoiceMapper;
use App\Services\Eis\Protocol;
use App\Services\Eis\WireBatches;
use App\Services\Eis\WireClient;
use App\Services\EisTransmissionCenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BirWireController extends Controller
{
    private function actor(Request $r): object
    {
        return app(ApiAccess::class)->require($r, ['super_admin']);
    }

    private function present(object $p): array
    {
        $data = (array) $p;
        unset($data['encrypted_credentials'],$data['encrypted_session']);

        return $data + ['credentials_configured' => (bool) $p->encrypted_credentials, 'blockers' => app(WireBatches::class)->blockers($p), 'base_url' => config('bir_eis.origins.'.$p->environment).config('bir_eis.prefixes.'.$p->environment)];
    }

    public function index(Request $r)
    {
        $this->actor($r);
        $r->validate(['page' => 'nullable|integer|min:1|max:100000', 'profile_id' => 'nullable|uuid']);
        $profiles = DB::table('bir_eis_profiles')->orderBy('name')->limit(500)->get()->map(fn ($p) => $this->present($p));
        $q = DB::table('bir_eis_batches')->select('id', 'profile_id', 'merchant_id', 'environment', 'submit_id', 'state', 'document_count', 'payload_hash', 'ack_id', 'error_code', 'last_error', 'attempts', 'submitted_at', 'completed_at', 'created_at');
        if ($r->filled('profile_id')) {
            $q->where('profile_id', $r->profile_id);
        }

        return response()->json(['profiles' => $profiles, 'batches' => $q->orderByDesc('created_at')->paginate(25), 'outbound_enabled' => (bool) config('bir_eis.outbound_enabled'), 'callback_enabled' => (bool) config('bir_eis.callback_enabled'), 'contract' => config('bir_eis.contract'), 'guide_url' => config('bir_eis.guide_url')])->header('Cache-Control', 'no-store');
    }

    public function save(Request $r, ?string $id = null)
    {
        $u = $this->actor($r);
        $v = $r->validate(['revision' => 'required|integer|min:0', 'name' => 'required|string|max:120', 'merchant_id' => 'required|string|exists:merchants,id', 'branch_code' => ['required', 'regex:/^\d{5}$/D'], 'environment' => 'required|in:certification,production', 'system_type' => 'required|in:CAS,POS', 'seller_type' => 'required|in:0,1,2', 'accreditation_id' => ['nullable', 'regex:/^[A-Z0-9]{8}$/D'], 'application_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/D'], 'ptu_number' => 'nullable|string|max:50', 'machine_id' => 'nullable|string|max:50', 'machine_serial' => 'nullable|string|max:50', 'schema_dialect' => 'required|in:workbook_20260926,guide_20260730', 'authority_reference' => 'nullable|string|max:2000', 'contract_reference' => 'nullable|string|max:2000', 'source_copies_authorized' => 'required|boolean', 'enabled' => 'required|boolean', 'reason' => 'required|string|min:10|max:1000']);
        if ($v['source_copies_authorized']) {
            abort_unless(strlen(trim($v['authority_reference'] ?? '')) >= 10, 422, 'Record reporting responsibility and duplicate-reporting review evidence.');
        }

        return DB::transaction(function () use ($u, $v, $id) {
            DB::table('business_registry_lock')->where('id', 1)->lockForUpdate()->first();
            $old = $id ? DB::table('bir_eis_profiles')->where('id', $id)->lockForUpdate()->first() : null;
            if ($id) {
                abort_unless($old, 404);
                abort_unless($old->revision === $v['revision'], 409, 'Connection changed. Reload before saving.');
                foreach (['merchant_id', 'branch_code', 'environment', 'system_type', 'accreditation_id', 'application_id'] as $field) {
                    abort_unless(($old->$field ?? null) === ($v[$field] ?? null) || (! $old->encrypted_credentials && ! DB::table('bir_eis_batches')->where('profile_id', $id)->exists()), 422, 'Connection identity is immutable after credentials or batches are recorded.');
                }
                abort_if(DB::table('bir_eis_batches')->where('profile_id', $id)->whereNotIn('state', ['accepted', 'rejected', 'partial', 'cancelled'])->exists(), 409, 'Resolve or cancel outstanding batches before changing this connection.');
            }
            $data = collect($v)->except(['revision', 'reason'])->all();
            $pid = $id ?? (string) Str::uuid();
            $data += ['revision' => ($old?->revision ?? 0) + 1, 'updated_at' => now()];
            if ($id) {
                DB::table('bir_eis_profiles')->where('id', $id)->update($data + ['encrypted_session' => null, 'session_expires_at' => null]);
            } else {
                DB::table('bir_eis_profiles')->insert($data + ['id' => $pid, 'created_at' => now()]);
            }
            app(BirCompliance::class)->audit('bir.connection.saved', $pid, (string) $u->id, ['environment' => $v['environment'], 'merchant_id' => $v['merchant_id'], 'revision' => $data['revision'], 'reason' => $v['reason']]);

            return response()->json(['profile' => $this->present(DB::table('bir_eis_profiles')->find($pid))]);
        }, 3);
    }

    public function credentials(Request $r, string $id)
    {
        $u = $this->actor($r);
        $v = $r->validate(['revision' => 'required|integer|min:1', 'user_id' => ['required', 'string', 'max:100', 'regex:/^[^\r\n]+$/D'], 'password' => 'required|string|max:256', 'application_key' => 'required|string|min:16|max:512', 'bir_public_key' => 'required|string|max:16000', 'signing_private_key' => 'required|string|max:32000', 'key_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/D'], 'key_expires_at' => 'required|date|after:now', 'reason' => 'required|string|min:10|max:1000']);
        try {
            Protocol::key($v['bir_public_key']);
            $private = Protocol::key($v['signing_private_key'], true);
            $fingerprint = hash('sha256', openssl_pkey_get_details($private)['key']);
            Protocol::rsa(Protocol::json(['userId' => $v['user_id'], 'password' => $v['password'], 'authKey' => str_repeat('0', 32)]), $v['bir_public_key']);
        } catch (\Throwable) {
            abort(422, 'The credential keys or RSA authentication length are invalid. Check the BIR-issued bundle.');
        }

        return DB::transaction(function () use ($u, $v, $id, $fingerprint) {
            $p = DB::table('bir_eis_profiles')->where('id', $id)->lockForUpdate()->first();
            abort_unless($p, 404);
            abort_unless($p->revision === $v['revision'], 409, 'Connection changed.');
            $pending = DB::table('bir_eis_batches')->where('profile_id', $id)->whereNotIn('state', ['accepted', 'rejected', 'partial', 'cancelled'])->get();
            abort_if($pending->contains(fn ($b) => ! $b->submitted_at || ($b->lease_until && now()->lte($b->lease_until))), 409, 'Cancel unsent batches and wait for active requests before rotating credentials. Submitted batches retain their original Submit IDs.');
            if ($pending->isNotEmpty()) {
                $old = app(WireClient::class)->credentials($p);
                abort_unless($old['user_id'] === $v['user_id'], 422, 'An outstanding submission must be reconciled using the same BIR user identity.');
            }
            abort_unless($p->accreditation_id && $p->application_id, 422, 'Save the BIR accreditation and application IDs first.');
            $bundle = collect($v)->only(['user_id', 'password', 'application_key', 'bir_public_key', 'signing_private_key', 'key_id'])->all();
            DB::table('bir_eis_profiles')->where('id', $id)->update(['encrypted_credentials' => Crypt::encryptString(Protocol::json($bundle)), 'key_fingerprint' => $fingerprint, 'key_expires_at' => CarbonImmutable::parse($v['key_expires_at'])->utc()->format('Y-m-d H:i:s'), 'encrypted_session' => null, 'session_expires_at' => null, 'authenticated_at' => null, 'revision' => $p->revision + 1, 'updated_at' => now()]);
            DB::table('bir_eis_batches')->whereIn('id', $pending->pluck('id'))->update(['profile_revision' => $p->revision + 1, 'updated_at' => now()]);
            app(BirCompliance::class)->audit('bir.credentials.replaced', $id, (string) $u->id, ['fingerprint' => $fingerprint, 'reason' => $v['reason'], 'environment' => $p->environment]);

            return response()->json(['message' => 'Credential bundle encrypted. No authentication request or invoice submission was made.']);
        }, 3);
    }

    public function pause(Request $r, string $id)
    {
        $u = $this->actor($r);
        $r->validate(['reason' => 'required|string|min:10|max:1000']);
        DB::transaction(function () use ($id, $u, $r) {
            $p = DB::table('bir_eis_profiles')->where('id', $id)->lockForUpdate()->first();
            abort_unless($p, 404);
            DB::table('bir_eis_profiles')->where('id', $id)->update(['enabled' => false, 'encrypted_session' => null, 'session_expires_at' => null, 'updated_at' => now()]);
            app(BirCompliance::class)->audit('bir.connection.paused', $id, (string) $u->id, ['reason' => $r->reason]);
        });

        return response()->json(['message' => 'Outbound requests paused. Existing batches and evidence are preserved.']);
    }

    public function resume(Request $r, string $id)
    {
        $u = $this->actor($r);
        $v = $r->validate(['revision' => 'required|integer|min:1', 'reason' => 'required|string|min:10|max:1000']);
        DB::transaction(function () use ($id, $u, $v) {
            $p = DB::table('bir_eis_profiles')->where('id', $id)->lockForUpdate()->first();
            abort_unless($p, 404);
            abort_unless($p->revision === $v['revision'] && $p->contract_reference, 409, 'Reload and confirm the BIR contract first.');
            DB::table('bir_eis_profiles')->where('id', $id)->update(['enabled' => true, 'updated_at' => now()]);
            app(BirCompliance::class)->audit('bir.connection.resumed', $id, (string) $u->id, ['reason' => $v['reason'], 'environment' => $p->environment]);
        });

        return response()->json(['message' => 'Connection resumed. Deployment and per-batch authority checks still apply.']);
    }

    public function probe(Request $r, string $id)
    {
        $u = $this->actor($r);
        $p = DB::table('bir_eis_profiles')->find($id);
        abort_unless($p, 404);
        try {
            app(WireClient::class)->session($p);
        } catch (\Throwable $e) {
            abort(409, $e instanceof \RuntimeException ? $e->getMessage() : 'BIR authentication could not be verified.');
        }
        app(BirCompliance::class)->audit('bir.authentication.verified', $id, (string) $u->id, ['environment' => $p->environment]);

        return response()->json(['message' => 'Authentication succeeded. This verifies credential access only; no invoice was sent.', 'profile' => $this->present(DB::table('bir_eis_profiles')->find($id))]);
    }

    public function authorityOptions(Request $r, string $id)
    {
        $this->actor($r);
        $p = DB::table('bir_eis_profiles')->find($id);
        abort_unless($p, 404);

        return response()->json([
            'permits' => DB::table('taxpayer_authorizations')->where('merchant_id', $p->merchant_id)->where('branch_code', $p->branch_code)->where('environment', 'production')->orderByDesc('created_at')->limit(100)->get(['id', 'kind', 'reference', 'status', 'valid_until']),
            'accreditations' => DB::table('esp_accreditations')->orderByDesc('created_at')->limit(100)->get(['id', 'solution_name', 'solution_version', 'certificate_number', 'status']),
            'record' => collect((array) app(BirCompliance::class)->integration($p->merchant_id, $p->branch_code, 'production'))->only(['id', 'permit_to_issue_number', 'permit_to_transmit_number', 'eis_certificate_number', 'certificate_expires_at', 'written_authorization_at', 'taxpayer_authorization_reference'])->all(),
        ])->header('Cache-Control', 'no-store');
    }

    public function authority(Request $r, string $id)
    {
        $u = $this->actor($r);
        $v = $r->validate(['revision' => 'required|integer|min:1', 'pti_id' => 'required|string|max:100', 'ptt_id' => 'required|string|max:100', 'esp_accreditation_id' => 'required|string|max:100', 'certificate_number' => 'required|string|max:200', 'certificate_expires_at' => 'required|date|after:now', 'written_authorization_at' => 'required|date|before_or_equal:now', 'evidence' => 'required|string|min:20|max:2000', 'reason' => 'required|string|min:10|max:1000']);

        return DB::transaction(function () use ($u, $v, $id) {
            $p = DB::table('bir_eis_profiles')->where('id', $id)->lockForUpdate()->first();
            abort_unless($p && $p->environment === 'production', 422, 'Production authority requires a Production connection.');
            abort_unless($p->revision === $v['revision'], 409, 'Connection changed. Reload before saving authority.');
            abort_if(DB::table('bir_eis_batches')->where('profile_id', $id)->whereNotIn('state', ['accepted', 'rejected', 'partial', 'cancelled'])->exists(), 409, 'Resolve existing batches before replacing authority evidence.');
            $permits = [];
            foreach (['PTI' => 'pti_id', 'PTT' => 'ptt_id'] as $kind => $field) {
                $permit = DB::table('taxpayer_authorizations')->where('id', $v[$field])->lockForUpdate()->first();
                abort_unless($permit && $permit->kind === $kind && $permit->merchant_id === $p->merchant_id && $permit->branch_code === $p->branch_code && $permit->environment === 'production' && in_array(app(TaxpayerAuthorizationController::class)->effective($permit), ['active', 'expiring_soon'], true), 422, 'Select current reviewed PTI and PTT records for this exact merchant, branch and environment.');
                $permits[$kind] = $permit;
            }
            $policy = app(BirCompliance::class);
            $accreditation = $policy->accreditation($v['esp_accreditation_id']);
            abort_unless($accreditation['authorized'], 422, 'Complete verified solution accreditation and its controls before linking production authority.');
            $merchant = DB::table('merchants')->find($p->merchant_id);
            $old = $policy->integration($p->merchant_id, $p->branch_code, 'production');
            $integrationId = $old?->id ?? (string) Str::uuid();
            $data = ['merchant_id' => $p->merchant_id, 'merchant_name' => $merchant->business_name, 'taxpayer_tin' => $merchant->tin, 'branch_code' => $p->branch_code, 'environment' => 'production', 'status' => 'authority_recorded', 'esp_accreditation_id' => $v['esp_accreditation_id'], 'pti_status' => 'approved', 'permit_to_issue_number' => $permits['PTI']->reference, 'ptt_status' => 'approved', 'permit_to_transmit_number' => $permits['PTT']->reference, 'ptt_issued_at' => $permits['PTT']->issued_at, 'taxpayer_authorization_reference' => $v['evidence'], 'written_authorization_at' => CarbonImmutable::parse($v['written_authorization_at'])->utc()->format('Y-m-d H:i:s'), 'certification_status' => 'certified', 'eis_certificate_number' => $v['certificate_number'], 'certificate_expires_at' => CarbonImmutable::parse($v['certificate_expires_at'])->utc()->format('Y-m-d H:i:s'), 'last_certification_at' => now(), 'endpoint_base_url' => config('bir_eis.origins.production').config('bir_eis.prefixes.production'), 'certification_url' => config('bir_eis.origins.certification'), 'json_schema_version' => $p->schema_dialect, 'api_version' => '2.3', 'cas_ptu_number' => $p->ptu_number, 'encryption_mode' => 'BIR AES-256-CBC session envelope / RSA authentication', 'settings' => Protocol::json(['wire_profile_id' => $p->id, 'certification_evidence' => $v['evidence']]), 'updated_at' => now()];
            if ($old) {
                DB::table('eis_integrations')->where('id', $integrationId)->update($data);
            } else {
                DB::table('eis_integrations')->insert($data + ['id' => $integrationId, 'created_at' => now()]);
            }
            DB::table('bir_eis_profiles')->where('id', $id)->update(['revision' => $p->revision + 1, 'authority_reference' => $v['evidence'], 'updated_at' => now()]);
            $policy->audit('bir.authority.linked', $id, (string) $u->id, ['integration_id' => $integrationId, 'pti_id' => $v['pti_id'], 'ptt_id' => $v['ptt_id'], 'certificate_number' => $v['certificate_number'], 'evidence' => $v['evidence'], 'reason' => $v['reason']]);

            return response()->json(['message' => 'Issued authority and certification evidence linked. Credentials, coverage and per-batch release checks remain enforced.']);
        }, 3);
    }

    public function candidates(Request $r, string $id, EisTransmissionCenter $center)
    {
        $this->actor($r);
        $p = DB::table('bir_eis_profiles')->find($id);
        abort_unless($p, 404);
        $r->validate(['page' => 'nullable|integer|min:1|max:100000', 'search' => 'nullable|string|max:100']);
        $f = ['merchant' => $p->merchant_id];
        if ($r->filled('search')) {
            $f['search'] = $r->search;
        }
        $q = $center->query($f)->where('seller_branch_key', $p->branch_code);

        return response()->json(['documents' => $q->orderByDesc('business_date')->orderByDesc('document_id')->paginate(25)->through(fn ($x) => $center->row($x))]);
    }

    public function prepare(Request $r, string $id, WireBatches $batches)
    {
        $u = $this->actor($r);
        $p = DB::table('bir_eis_profiles')->find($id);
        abort_unless($p, 404);
        $v = $r->validate(['request_id' => 'required|uuid', 'documents' => 'required|array|min:1|max:100', 'documents.*' => 'required|string|max:120|distinct', 'metadata' => 'nullable|array']);
        $this->validateMetadata($v['metadata'] ?? []);

        return response()->json($batches->prepare($p, $v['documents'], $v['metadata'] ?? [], $v['request_id'], (string) $u->id));
    }

    private function validateMetadata(array $metadata): void
    {
        abort_if(count($metadata) > 100, 422);
        foreach ($metadata as $m) {
            validator($m, ['buyer_branch_code' => ['nullable', 'regex:/^\d{5}$/D'], 'buyer_not_registered' => 'nullable|boolean', 'correction_code' => 'nullable|in:01,02,03,04,05,09', 'previous_uids' => 'nullable|array', 'previous_uids.*' => 'required|string|size:24', 'remarks' => 'nullable|string|max:500', 'rbe_local_sale' => 'nullable|boolean', 'withholding_by_class' => 'nullable|array', 'withholding_by_class.*' => 'array:income,vat,percentage', 'withholding_by_class.*.*' => 'integer|min:0|max:1000000000000'])->validate();
        }
    }

    public function detail(Request $r, string $id)
    {
        $this->actor($r);
        $b = DB::table('bir_eis_batches')->find($id);
        abort_unless($b, 404);
        $data = (array) $b;
        unset($data['encrypted_payload']);

        return response()->json(['batch' => $data, 'documents' => DB::table('bir_eis_documents')->where('batch_id', $id)->get(), 'payload' => json_decode(Crypt::decryptString($b->encrypted_payload), true), 'attempts' => DB::table('bir_eis_wire_attempts')->where('batch_id', $id)->orderByDesc('created_at')->limit(50)->get(['id', 'operation', 'http_status', 'error_code', 'response_hash', 'created_at'])])->header('Cache-Control', 'no-store');
    }

    public function release(Request $r, string $id, WireBatches $batches)
    {
        $u = $this->actor($r);
        $r->validate(['confirm_destination' => 'required|in:certification,production']);
        abort_unless(DB::table('bir_eis_batches')->where('id', $id)->where('environment', $r->confirm_destination)->exists(), 422, 'Confirm the exact destination environment.');
        $batches->release($id, (string) $u->id);

        return response()->json(['message' => 'Batch queued for the selected BIR environment. Final results are obtained through authenticated inquiry.']);
    }

    public function cancel(Request $r, string $id)
    {
        $u = $this->actor($r);
        $r->validate(['reason' => 'required|string|min:10|max:1000']);
        DB::transaction(function () use ($r, $id, $u) {
            $b = DB::table('bir_eis_batches')->where('id', $id)->lockForUpdate()->first();
            abort_unless($b, 404);
            abort_unless(in_array($b->state, ['prepared', 'queued'], true) && ! $b->submitted_at && ! $b->lease_token, 409, 'A potentially submitted batch cannot be cancelled or replayed. Inquire using its original Submit ID.');
            DB::table('bir_eis_batches')->where('id', $id)->update(['state' => 'cancelled', 'updated_at' => now()]);
            DB::table('bir_eis_documents')->where('batch_id', $id)->update(['reporting_identity' => null]);
            app(BirCompliance::class)->audit('bir.wire_batch.cancelled', $id, (string) $u->id, ['reason' => $r->reason]);
        });

        return response()->json(['message' => 'Unsent batch cancelled. Its payload and mapping history are preserved.']);
    }

    public function inquire(Request $r, string $id)
    {
        $u = $this->actor($r);
        DB::transaction(function () use ($id, $u) {
            $b = DB::table('bir_eis_batches')->where('id', $id)->lockForUpdate()->first();
            abort_unless($b, 404);
            abort_unless($b->submitted_at && in_array($b->state, ['processing', 'uncertain', 'reconciliation'], true) && (! $b->lease_until || now()->gt($b->lease_until)), 409, 'This batch does not require a manual inquiry.');
            DB::table('bir_eis_batches')->where('id', $id)->update(['state' => 'uncertain', 'attempts' => 0, 'available_at' => now(), 'lease_token' => null, 'lease_until' => null, 'updated_at' => now()]);
            app(BirCompliance::class)->audit('bir.inquiry.requested', $id, (string) $u->id, ['submit_id' => $b->submit_id]);
        });

        return response()->json(['message' => 'Result inquiry scheduled using the existing Submit ID. No invoice POST will be replayed.']);
    }

    public function fixture(Request $r, string $id, WireBatches $batches)
    {
        $u = $this->actor($r);
        $r->validate(['request_id' => 'required|uuid']);

        return DB::transaction(function () use ($r, $id, $u, $batches) {
            $p = DB::table('bir_eis_profiles')->where('id', $id)->lockForUpdate()->first();
            abort_unless($p && $p->environment === 'certification', 422, 'Synthetic fixtures require a certification profile.');
            $existing = DB::table('bir_eis_batches')->where('request_id', $r->request_id)->first();
            if ($existing) {
                abort_unless($existing->profile_id === $id, 409);

                return response()->json(['id' => $existing->id]);
            }
            $merchant = DB::table('merchants')->find($p->merchant_id);
            $amount = $p->seller_type === '1' ? 10000 : 11200;
            $vat = $p->seller_type === '1' ? 0 : 1200;
            $fixtureId = (string) Str::uuid();
            $model = ['schema' => 'NUERS-normalized-document-5', 'currency' => 'PHP', 'seller' => ['taxpayer_tin' => substr(preg_replace('/\D/', '', $merchant->tin), 0, 9), 'branch_code' => $p->branch_code, 'name' => $merchant->business_name, 'address' => $merchant->address ?? ''], 'issue_date' => now('Asia/Manila')->toDateString(), 'document_type' => 'sales_invoice', 'number' => 'CERT-'.strtoupper(substr($fixtureId, 0, 8)), 'buyer' => ['name' => 'SYNTHETIC CERTIFICATION CUSTOMER', 'tin' => '', 'address' => ''], 'totals' => ['amount_centavos' => $amount, 'withholding_centavos' => 0], 'items' => [['description' => 'Certification sample - no commercial sale', 'quantity' => '1', 'gross_base_centavos' => 10000, 'net_base_centavos' => 10000, 'regular_discount_base_centavos' => 0, 'statutory_discount_centavos' => 0, 'vat_centavos' => $vat, 'tax_type' => $vat ? 'vatable' : 'non_vat']]];
            $mapped = app(InvoiceMapper::class)->map($model, $p, ['buyer_branch_code' => '00000', 'buyer_not_registered' => true]);
            $records = [];
            foreach ($mapped as $class => $d) {
                $records[] = ['outbox_id' => $fixtureId, 'source' => 'fixture', 'source_id' => $fixtureId, 'tax_class' => (string) $class, 'invoice_uid' => $d['EisUniqueId'], 'document_number' => $d['CompInvoiceId'], 'source_hash' => hash('sha256', Protocol::json($model)), 'reporting_identity' => hash('sha256', $fixtureId.$class)];
            }

            return response()->json($batches->save($p, array_values($mapped), $records, $r->request_id, (string) $u->id));
        }, 3);
    }
}
