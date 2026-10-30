<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\MerchantSystemIntegration;
use App\Services\MerchantVerificationDocuments;
use App\Services\RegistrationIndustry;
use App\Services\TaxpayerAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class MerchantBusinessProfileController extends Controller
{
    public const FIELDS = ['trade_name', 'business_description', 'representative_name', 'representative_position', 'representative_email', 'representative_phone'];

    private function merchant(Request $r): object
    {
        $u = app(ApiAccess::class)->require($r, ['merchant']);
        $m = DB::table('merchants')->where('merchant_account_id', $u->id)->orderBy('id')->first();
        abort_unless($m, 403, 'Only the linked business owner can manage Business Profile.');
        abort_if(! $r->isMethod('GET') && in_array($m->status, ['suspended', 'inactive']), 403, 'This business is suspended. Preserved records remain available.');

        return $m;
    }

    private function lockedMerchant(Request $r, string $id): object
    {
        $m = DB::table('merchants')->where('id', $id)->lockForUpdate()->first();
        abort_unless($m && (string) $m->merchant_account_id === (string) $r->attributes->get('api_actor')->id, 403, 'Business ownership changed. Sign in again.');
        abort_if(in_array($m->status, ['suspended', 'inactive']), 403, 'This business is suspended.');

        return $m;
    }

    private function details(?object $row, ?object $reg): array
    {
        $v = $row ? json_decode(Crypt::decryptString($row->encrypted_details), true, flags: JSON_THROW_ON_ERROR) : [];

        return array_replace(array_fill_keys(self::FIELDS, ''), ['representative_name' => $reg->contact_name ?? '', 'representative_position' => $reg->contact_position ?? '', 'representative_phone' => $reg->mobile ?? ''], $v);
    }

    private function fingerprint(object $m, ?object $row, array $docs): string
    {
        return hash('sha256', json_encode([$m->registry_revision ?? 1, $row->revision ?? 0, array_map(fn ($d) => [$d['kind'], $d['id'], $d['status']], $docs['items'])]));
    }

    public function show(Request $r)
    {
        return response()->json($this->present($this->merchant($r)))->header('Cache-Control', 'private, no-store');
    }

    private function present(object $m): array
    {
        $row = DB::table('merchant_business_profiles')->where('merchant_id', $m->id)->first();
        $reg = DB::table('business_registrations')->where('merchant_id', $m->id)->orderByDesc('created_at')->first();
        $meta = json_decode($m->registry_details ?? '{}', true) ?: [];
        $docs = app(MerchantVerificationDocuments::class)->present($m->id);
        $permits = DB::table('taxpayer_authorizations')->where('merchant_id', $m->id)->where('environment', 'production')->orderByDesc('created_at')->get()->map(function ($p) {
            return ['id' => $p->id, 'kind' => $p->kind, 'reference' => $p->reference, 'branch' => $p->branch_code ?? null, 'status' => app(TaxpayerAuthority::class)->status($p), 'reviewed' => (bool) ($p->reviewed_by && $p->reviewed_at && $p->evidence), 'issued_at' => $p->issued_at, 'valid_until' => $p->valid_until];
        })->all();
        $integrations = DB::table('eis_integrations')->where('merchant_id', $m->id)->where('environment', 'production')->orderBy('branch_code')->get();
        $readiness = [];
        foreach ($integrations as $i) {
            $issue = app(BirCompliance::class)->gate($i, false);
            $transmit = app(BirCompliance::class)->gate($i, true);
            $readiness[] = ['branch' => $i->branch_code, 'can_issue' => $issue['can_issue'], 'can_transmit' => $transmit['can_transmit'], 'blockers' => array_values(array_unique(array_merge($issue['blockers'], $transmit['blockers'])))];
        }
        $events = DB::table('eis_audit_events')->where('subject_id', $m->id)->where(function ($q) {
            $q->where('event', 'like', 'business.profile.%')->orWhere('event', 'like', 'business.verification.%')->orWhere('event', 'like', 'business_registry.%');
        })->orderByDesc('id')->limit(30)->get(['id', 'event', 'occurred_at'])->map(fn ($e) => ['id' => $e->id, 'action' => $e->event, 'date' => CarbonImmutable::parse($e->occurred_at, 'UTC')->toIso8601String()])->all();
        $lastReview = DB::table('merchant_verification_documents')->where('merchant_id', $m->id)->whereNotNull('reviewed_at')->max('reviewed_at');
        $fingerprint = $this->fingerprint($m, $row, $docs);
        $submitted = $row?->submitted_fingerprint && hash_equals($row->submitted_fingerprint, $fingerprint);

        return ['business' => ['id' => $m->id, 'business_name' => $m->business_name, 'owner_name' => $m->owner_name, 'business_type' => $m->business_type, 'sector' => app(RegistrationIndustry::class)->options()[$m->sector] ?? $m->sector, 'tin' => $m->tin, 'rdo_code' => $m->rdo_code, 'rdo_name' => $m->rdo_name, 'registration_number' => $meta['registration_number'] ?? null, 'registration_date' => $m->bir_registration_date, 'address' => $m->business_registration_address ?: $m->address, 'city' => $m->city, 'region' => $m->region, 'zip_code' => $m->zip_code, 'email' => $m->email ?: $m->merchant_account_email, 'phone' => $m->phone, 'website' => $m->website, 'vat_registered' => (bool) $m->vat_registered, 'status' => $m->status, 'created_at' => $m->created_at, 'logo' => $m->logo_url ?? null],
            'details' => $this->details($row, $reg), 'revision' => (int) ($row->revision ?? 0), 'registry_revision' => (int) $m->registry_revision, 'documents' => $docs, 'permits' => $permits, 'readiness' => $readiness, 'activities' => $events,
            'review' => ['stage' => $meta['stage'] ?? ($m->status === 'active' ? 'onboarded' : 'evaluation'), 'requested_at' => $row?->review_requested_at, 'current_submission' => (bool) $submitted, 'last_review_at' => $lastReview, 'can_submit' => $docs['uploaded'] === $docs['total'] && ! collect($docs['items'])->contains('status', 'needs_changes') && ! $submitted && $m->status !== 'active' && ! in_array($m->status, ['suspended', 'inactive'])],
            'integration' => app(MerchantSystemIntegration::class)->present($m), 'account_linked' => true, 'updated_at' => now()->toIso8601String()];
    }

    public function update(Request $r)
    {
        $m = $this->merchant($r);
        abort_if(array_diff(array_keys($r->all()), ['revision','registry_revision','phone','website','details']), 422, 'Only Contact Number, Website and Business Description can be saved directly. Request administrator approval for other fields.');
        $v = $r->validate(['revision' => 'required|integer|min:0','registry_revision' => 'required|integer|min:1','details' => 'required|array:business_description','details.business_description' => 'nullable|string|max:500','phone' => ['nullable','string','max:32','regex:/^\+?[0-9 ()-]{7,32}$/'],'website' => 'nullable|url:http,https|max:255']);
        if (! empty($v['website'])) {
            abort_if(parse_url($v['website'], PHP_URL_USER) !== null || parse_url($v['website'], PHP_URL_PASS) !== null, 422, 'Website URLs must not include credentials.');
        }

        return DB::transaction(function () use ($r, $m, $v) {
            $m = $this->lockedMerchant($r, $m->id);
            $row = DB::table('merchant_business_profiles')->where('merchant_id', $m->id)->lockForUpdate()->first();
            abort_unless((int) ($row->revision ?? 0) === $v['revision'] && (int) $m->registry_revision === $v['registry_revision'], 409, 'This profile changed in another window. Refresh before saving.');
            $details = $row ? json_decode(Crypt::decryptString($row->encrypted_details), true, flags: JSON_THROW_ON_ERROR) : [];
            $details['business_description'] = trim((string) ($v['details']['business_description'] ?? ''));
            $payload = ['encrypted_details' => Crypt::encryptString(json_encode($details, JSON_THROW_ON_ERROR)), 'revision' => ($row->revision ?? 0) + 1, 'updated_at' => now()];
            if ($row) {
                DB::table('merchant_business_profiles')->where('merchant_id', $m->id)->update($payload);
            } else {
                DB::table('merchant_business_profiles')->insert(['merchant_id' => $m->id, 'created_at' => now(), ...$payload]);
            }
            DB::table('merchants')->where('id', $m->id)->update(['phone' => $v['phone'] ?? null, 'website' => $v['website'] ?? null, 'registry_revision' => $m->registry_revision + 1, 'updated_at' => now()]);
            app(BirCompliance::class)->audit('business.profile.updated', $m->id, (string) $r->attributes->get('api_actor')->id, ['fields' => array_keys($details)]);


            return response()->json($this->present(DB::table('merchants')->where('id', $m->id)->first()))->header('Cache-Control', 'private, no-store');
        });
    }

    public function submit(Request $r)
    {
        $m = $this->merchant($r);
        $v = $r->validate(['revision' => 'required|integer|min:0', 'registry_revision' => 'required|integer|min:1']);

        return DB::transaction(function () use ($r, $m, $v) {
            $m = $this->lockedMerchant($r, $m->id);
            $row = DB::table('merchant_business_profiles')->where('merchant_id', $m->id)->lockForUpdate()->first();
            abort_unless((int) ($row->revision ?? 0) === $v['revision'] && (int) $m->registry_revision === $v['registry_revision'], 409, 'The profile or documents changed. Refresh before submitting.');
            $docs = app(MerchantVerificationDocuments::class)->present($m->id);
            $fingerprint = $this->fingerprint($m, $row, $docs);
            if ($row?->submitted_fingerprint && hash_equals($row->submitted_fingerprint, $fingerprint)) {
                return response()->json($this->present($m))->header('Cache-Control', 'private, no-store');
            }
            abort_if($m->status === 'active', 409, 'This account is already active. Document replacements are reviewed independently.');
            abort_unless($docs['uploaded'] === $docs['total'] && ! collect($docs['items'])->contains('status', 'needs_changes'), 422, 'Upload all five required documents and replace any files marked Needs changes before submitting.');
            foreach (app(MerchantVerificationDocuments::class)->latest($m->id) as $d) {
                app(MerchantVerificationDocuments::class)->assertIntegrity($d);
            }
            $meta = json_decode($m->registry_details ?? '{}', true) ?: [];
            $meta['review_requested_at'] = now()->toIso8601String();
            DB::table('merchants')->where('id', $m->id)->update(['registry_details' => json_encode($meta), 'registry_revision' => $m->registry_revision + 1, 'updated_at' => now()]);
            $m = DB::table('merchants')->where('id', $m->id)->first();
            $revision = ($row->revision ?? 0) + 1;
            $newRow = (object) ['revision' => $revision];
            $payload = ['submitted_fingerprint' => $this->fingerprint($m, $newRow, $docs), 'review_requested_at' => now(), 'revision' => $revision, 'updated_at' => now()];
            if ($row) {
                DB::table('merchant_business_profiles')->where('merchant_id', $m->id)->update($payload);
            } else {
                DB::table('merchant_business_profiles')->insert(['merchant_id' => $m->id, 'encrypted_details' => Crypt::encryptString('{}'), 'created_at' => now(), ...$payload]);
            }
            app(BirCompliance::class)->audit('business.profile.review_requested', $m->id, (string) $r->attributes->get('api_actor')->id, ['documents' => $docs['uploaded'], 'submission_revision' => $revision]);

            return response()->json($this->present($m))->header('Cache-Control', 'private, no-store');
        });
    }
}
