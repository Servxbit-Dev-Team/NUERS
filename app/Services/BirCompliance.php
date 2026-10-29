<?php

namespace App\Services;

use App\Http\Controllers\Api\TaxpayerAuthorizationController;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BirCompliance
{
    public function administrativeUser(Request $request): object
    {
        $user = $request->bearerToken() ? app(ApiAccess::class)->user($request) : null;
        abort_unless($user, 401, 'An authenticated administrative session is required.');
        $role = DB::table('profiles')->where('id', $user->id)->value('role');
        abort_unless(in_array($role, ['admin', 'super_admin', 'bir'], true), 403, 'Administrative monitoring access is required.');

        return $user;
    }

    public function assertSourceCaptureAllowed(): void
    {
        if (!config('bir_compliance.source_capture_accreditation_enforced')) return;
        $state=$this->accreditation();
        abort_unless($state['authorized'],409,'New Live source intake is paused by the accreditation policy. Existing records remain available.');
    }

    public function accreditation(?string $id = null): array
    {
        $record = null;
        if (Schema::hasTable('esp_accreditations')) {
            $q = DB::table('esp_accreditations');
            $record = ($id ? $q->where('id', $id) : $q->where('solution_name', config('bir_compliance.solution_name'))->where('solution_version', config('bir_compliance.solution_version')))->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->orderByDesc('created_at')->first();
        }
        $controls = $record ? DB::table('esp_accreditation_controls')->where('accreditation_id', $record->id)->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get() : collect();
        $rows = function (string $category) use ($controls) {
            return collect(config('bir_compliance.'.$category))->map(function ($title, $code) use ($controls, $category) {
                $control = $controls->first(fn ($row) => $row->category === $category && str_pad($row->code, 2, '0', STR_PAD_LEFT) === str_pad($code, 2, '0', STR_PAD_LEFT));
                $verified = $control && $control->verified_at && $control->verified_by && $control->evidence_reference;
                $naAllowed = $category === 'annex' && in_array((string) $code, ['03', '08', '11'], true);
                $passed = $verified && ($control->status === 'passed' || ($control->status === 'not_applicable' && $naAllowed));

                return ['code' => (string) $code, 'title' => $title, 'status' => $passed ? $control->status : (in_array($control?->status, ['failed', 'submitted'], true) ? $control->status : 'pending'), 'passed' => (bool) $passed, 'evidence_reference' => $control?->evidence_reference, 'verified_at' => $control?->verified_at];
            })->values()->all();
        };
        $annex = $rows('annex');
        $stages = $rows('stages');
        $blockers = [];
        if (! $record || $record->status !== 'accredited' || ! $record->certificate_number || ! $record->verified_at || ! $record->verified_by || ! $record->evidence_reference) {
            $blockers[] = 'Verified BIR-issued solution accreditation certificate is not recorded.';
        }
        $today = CarbonImmutable::now('Asia/Manila');
        $expires = $record?->expires_at ? CarbonImmutable::parse($record->expires_at, 'Asia/Manila')->endOfDay() : null;
        if (! $record?->issued_at || ! $expires || $expires->isPast()) {
            $blockers[] = 'A current accreditation validity period is required.';
        }
        if ($record?->issued_at && $expires && $expires->greaterThan(CarbonImmutable::parse($record->issued_at, 'Asia/Manila')->addYears(5)->endOfDay())) {
            $blockers[] = 'Recorded accreditation exceeds the five-year validity limit.';
        }
        if ($record?->issued_at && CarbonImmutable::parse($record->issued_at, 'Asia/Manila')->isFuture()) {
            $blockers[] = 'Accreditation has not yet taken effect.';
        }
        if ($record && ($record->solution_version !== config('bir_compliance.solution_version') || ! config('bir_compliance.configuration_reference') || $record->configuration_reference !== config('bir_compliance.configuration_reference'))) {
            $blockers[] = 'Approved version and deployment configuration must match the running solution.';
        }
        if ($record?->material_change_pending) {
            $blockers[] = 'Material change requires prior BIR approval and revalidation.';
        }
        if ($record && (! $record->annual_review_at || CarbonImmutable::parse($record->annual_review_at, 'Asia/Manila')->addYear()->lessThan($today))) {
            $blockers[] = 'Annual compliance review evidence is required.';
        }
        if (count(array_filter($annex, fn ($r) => $r['passed'])) !== 17) {
            $blockers[] = 'Annex A documentary requirements are incomplete or unverified.';
        }
        if (count(array_filter($stages, fn ($r) => $r['passed'])) !== 7) {
            $blockers[] = 'All seven mandatory BIR accreditation stages must pass.';
        }

        return ['record' => $record, 'annex' => $annex, 'stages' => $stages, 'blockers' => $blockers, 'authorized' => ! $blockers,
            'renewal_due' => $expires && $expires->lessThanOrEqualTo($today->addDays(60)),
            'renewal_due_at' => $expires?->subDays(60)->toDateString(), 'reference' => config('bir_compliance.reference')];
    }

    public function integration(?string $merchantId = null, ?string $branch = null, ?string $environment = null): ?object
    {
        if (! Schema::hasTable('eis_integrations')) {
            return null;
        }
        $query = DB::table('eis_integrations');
        if ($merchantId !== null) {
            $query->where('merchant_id', $merchantId);
        }

        if ($branch !== null) {
            $query->where('branch_code', $branch);
        }
        if ($environment !== null) {
            $query->where('environment', $environment);
        }

        return $query->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->orderByDesc('updated_at')->first();
    }

    public function gate(?object $integration, bool $transmission = true, bool $implementedWireAdapter = false): array
    {
        $accreditation = $this->accreditation($integration?->esp_accreditation_id ?? null);
        $blockers = $accreditation['blockers'];
        if (! $integration || $integration->pti_status !== 'approved' || ! $integration->permit_to_issue_number || ! $integration->taxpayer_authorization_reference) {
            $blockers[] = 'Taxpayer PTI Electronic Invoice authority and supporting evidence are required; CAS PTU/AC is insufficient.';
        }
        if (! $integration?->written_authorization_at) {
            $blockers[] = 'Written client-taxpayer authorization is required for ESP integration services.';
        }
        if ($transmission) {
            if (! $integration || $integration->certification_status !== 'certified' || ! $integration->eis_certificate_number || ! $integration->certificate_expires_at || CarbonImmutable::parse($integration->certificate_expires_at)->isPast()) {
                $blockers[] = 'Current taxpayer EIS certification is required.';
            }
            if (! $integration || $integration->ptt_status !== 'approved' || ! $integration->permit_to_transmit_number) {
                $blockers[] = 'Taxpayer Permit to Transmit is required separately from PTI.';
            }
            if (! $implementedWireAdapter && (! $integration?->credentials_present || ! $integration?->public_key_fingerprint)) {
                $blockers[] = 'BIR production credentials and key material are not registered.';
            }
        }
        // Maintained permit records constrain legacy integration references; recording one never enables transport.
        if ($integration && Schema::hasTable('taxpayer_authorizations')) {
            foreach (['PTI' => $integration->permit_to_issue_number, 'PTT' => $transmission ? $integration->permit_to_transmit_number : null] as $kind => $reference) {
                if (! $reference) {
                    continue;
                }
                $permit = DB::table('taxpayer_authorizations')->where('kind', $kind)->where('reference', $reference)->where('merchant_id', $integration->merchant_id)->where('environment', $integration->environment)->where('branch_code', $integration->branch_code)->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
                if (! $permit || $permit->merchant_id !== $integration->merchant_id || $permit->environment !== $integration->environment || ($permit->branch_code ?? null) !== $integration->branch_code || ! in_array(app(TaxpayerAuthorizationController::class)->effective($permit), ['active', 'expiring_soon'])) {
                    $blockers[] = $kind.' maintained authority is not current for this taxpayer and environment.';
                }
            }
        }
        if ($integration && ! preg_match('/^[0-9]{5}$/', (string) $integration->branch_code)) {
            $blockers[] = 'A verified five-digit BIR branch is required.';
        }
        if ($integration?->merchant_id && DB::table('merchants')->where('id', $integration->merchant_id)->value('status') !== 'active') {
            $blockers[] = 'Taxpayer business is not active.';
        }
        if ($integration && ! Schema::hasTable('taxpayer_authorizations')) {
            $blockers[] = 'Maintained taxpayer authority records are required.';
        }
        if ($integration && Schema::hasTable('merchant_branches') && DB::table('merchant_branches')->where('merchant_id',$integration->merchant_id)->where('bir_branch_code',$integration->branch_code)->where('status','inactive')->exists()) {
            $blockers[] = 'The registered branch is operationally inactive.';
        }
        $authorizationReady = ! $blockers;
        if ($transmission && ! $implementedWireAdapter && ! config('bir_compliance.transport_implemented')) {
            $blockers[] = 'Verified BIR production transport is not implemented; certification acknowledgements are simulations.';
        }

        if ($transmission && ! $implementedWireAdapter && ! config('bir_compliance.official_interface_verified')) {
            $blockers[] = 'The authorized BIR interface and official schema mapping have not been verified.';
        }
        if ($transmission && ! $implementedWireAdapter && ! config('bir_compliance.production_response_verification_implemented')) {
            $blockers[] = 'Authenticated BIR production response verification is not implemented.';
        }

        return ['can_issue' => ! $transmission && ! $blockers, 'can_transmit' => $transmission && ! $blockers,
            'authorization_ready' => $authorizationReady, 'transport_ready' => $implementedWireAdapter || (bool) config('bir_compliance.transport_implemented'),
            'blockers' => $blockers, 'accreditation' => $accreditation];
    }

    public function assertCanIssue(string $merchantId, ?string $branch = null, string $environment = 'production'): array
    {
        if ($branch === null) {
            abort_if(DB::table('eis_integrations')->where('merchant_id', $merchantId)->where('environment', $environment)->distinct()->count('branch_code') > 1, 409, 'Select an explicit verified branch when multiple branch integrations exist.');
        }
        $integration = $this->integration($merchantId, $branch, $environment);
        $gate = $this->gate($integration, false);
        abort_unless($gate['can_issue'], 409, 'Production issuance is blocked. Save a draft or use the controlled certification sandbox. '.implode(' ', $gate['blockers']));

        return ['permit_to_issue_number' => $integration->permit_to_issue_number, 'esp_accreditation_number' => $gate['accreditation']['record']->certificate_number];
    }

    public function deadline(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Asia/Manila')->setTimezone('Asia/Manila')->addDays(3)->endOfDay();
    }

    public function audit(string $event, ?string $subjectId, string $actor, array $details = []): void
    {
        if (! Schema::hasTable('eis_audit_head')) {
            return;
        }
        DB::transaction(function () use ($event, $subjectId, $actor, $details) {
            $head = DB::table('eis_audit_head')->where('id', 1)->lockForUpdate()->first();
            $at = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
            $json = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $hash = hash('sha256', json_encode([$head->last_hash, $event, $subjectId, $actor, $json, $at], JSON_THROW_ON_ERROR));
            DB::table('eis_audit_events')->insert(['event' => $event, 'subject_id' => $subjectId, 'actor_id' => $actor, 'details' => $json, 'occurred_at' => $at, 'previous_hash' => $head->last_hash, 'entry_hash' => $hash]);
            DB::table('eis_audit_head')->where('id', 1)->update(['last_hash' => $hash]);
            app(NotificationEvents::class)->recorded($event, $subjectId, $actor, $details, $hash);
        }, 3);
    }

    public function auditIntegrity(): bool
    {
        if (! Schema::hasTable('eis_audit_head')) {
            return false;
        }
        $previous = str_repeat('0', 64);
        foreach (DB::table('eis_audit_events')->lazyById(1000) as $row) {
            $expected = hash('sha256', json_encode([$previous, $row->event, $row->subject_id, $row->actor_id, $row->details, $row->occurred_at], JSON_THROW_ON_ERROR));
            if ($row->previous_hash !== $previous || ! hash_equals($expected, $row->entry_hash)) {
                return false;
            }
            $previous = $row->entry_hash;
        }

        return DB::table('eis_audit_head')->where('id', 1)->value('last_hash') === $previous;
    }
}
