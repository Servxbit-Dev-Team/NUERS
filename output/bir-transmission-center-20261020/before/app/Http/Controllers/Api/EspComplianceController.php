<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BirCompliance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EspComplianceController extends Controller
{
    public function show(Request $request)
    {
        $policy = app(BirCompliance::class); $user = $policy->administrativeUser($request);
        $accreditation = $policy->accreditation();
        return response()->json([
            ...$accreditation, 'production' => $policy->gate($policy->integration()),
            'can_record_bir_review' => DB::table('profiles')->where('id', $user->id)->value('role') === 'bir',
            'solution' => ['name' => config('bir_compliance.solution_name'), 'version' => config('bir_compliance.solution_version')],
            'rules' => ['transmission_calendar_days' => 3, 'restoration_calendar_days' => 3, 'critical_incident_report_hours' => 24, 'certificate_years' => 5, 'renewal_lead_days' => 60, 'annual_review' => true, 'track_record' => config('bir_compliance.track_record')],
            'audit_integrity' => $policy->auditIntegrity(),
            'incidents' => DB::table('eis_operational_incidents')->orderByDesc('detected_at')->limit(50)->get(),
            'source_notes' => ['The supplied circular and Annex A are the primary implementation references.', 'Annex A contains 17 requirements. Slides 32–33 omit joint-venture item 8 and renumber subsequent items.', 'The capacity requirement concerns verifiable supported systems over each preceding year; current NUERS dashboard totals alone do not establish it.', 'External certificates, BIR-supervised testing, hosting/data-residency attestations and legal approval require actual evidence.'],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function application(Request $request)
    {
        $policy = app(BirCompliance::class); $actor = $policy->administrativeUser($request);
        $version = (string) config('bir_compliance.solution_version');
        $existing = DB::table('esp_accreditations')->where('solution_name', config('bir_compliance.solution_name'))->where('solution_version', $version)->first();
        if ($existing) return response()->json(['id' => $existing->id]);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $version, $policy, $actor) {
            DB::table('esp_accreditations')->insert(['id' => $id, 'solution_name' => config('bir_compliance.solution_name'), 'solution_version' => $version, 'configuration_reference' => config('bir_compliance.configuration_reference') ?: null, 'status' => 'not_submitted', 'created_at' => now(), 'updated_at' => now()]);
            foreach (['annex', 'stages'] as $category) foreach (config('bir_compliance.'.$category) as $code => $title) {
                DB::table('esp_accreditation_controls')->insert(['id' => (string) Str::uuid(), 'accreditation_id' => $id, 'category' => $category, 'code' => (string) $code, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            }
            $policy->audit('esp.application.created', $id, (string) $actor->id, ['version' => $version]);
        });
        return response()->json(['id' => $id], 201);
    }

    public function evidence(Request $request, string $id)
    {
        $policy = app(BirCompliance::class); $actor = $policy->administrativeUser($request);
        $v = $request->validate(['category' => 'required|in:annex,stages', 'code' => 'required|string|max:2', 'evidence_reference' => 'required|string|min:8|max:2000']);
        $code = str_pad($v['code'], 2, '0', STR_PAD_LEFT);
        abort_unless(array_key_exists($code, config('bir_compliance.'.$v['category'])), 422, 'Unknown requirement.');
        DB::transaction(function () use ($id, $v, $code, $policy, $actor) {
            abort_unless(DB::table('esp_accreditations')->where('id', $id)->lockForUpdate()->first(), 404);
            $before = DB::table('esp_accreditation_controls')->where('accreditation_id', $id)->where('category', $v['category'])->where('code', $code)->first();
            DB::table('esp_accreditation_controls')->where('id', $before->id)->update(['status' => 'submitted', 'evidence_reference' => $v['evidence_reference'], 'verified_by' => null, 'verified_at' => null, 'updated_at' => now()]);
            $this->invalidateProgression($id, $v['category'], $code);
            $policy->audit('esp.evidence.submitted', $id, (string) $actor->id, ['before' => (array) $before, 'after' => $v]);
        });
        return response()->json(['message' => 'Evidence submitted for review.']);
    }

    private function invalidateProgression(string $id, string $category, string $code): void
    {
        $query = DB::table('esp_accreditation_controls')->where('accreditation_id', $id)->where('category', 'stages');
        if ($category === 'stages') $query->where('code', '>', $code);
        $query->update(['status' => 'pending', 'verified_at' => null, 'verified_by' => null, 'updated_at' => now()]);
        DB::table('esp_accreditations')->where('id', $id)->update(['status' => 'under_review', 'updated_at' => now()]);
    }

    public function review(Request $request, string $id)
    {
        $policy = app(BirCompliance::class); $actor = $policy->administrativeUser($request);
        abort_unless(DB::table('profiles')->where('id', $actor->id)->value('role') === 'bir', 403, 'A BIR reviewer must record external review evidence.');
        $values = $request->validate(['category' => 'required|in:annex,stages', 'code' => 'required|string|max:2', 'status' => 'required|in:passed,failed,not_applicable', 'evidence_reference' => 'required|string|min:8|max:2000']);
        $code = str_pad($values['code'], 2, '0', STR_PAD_LEFT);
        abort_unless(array_key_exists($code, config('bir_compliance.'.$values['category'])), 422, 'Unknown requirement.');
        abort_if($values['status'] === 'not_applicable' && ($values['category'] !== 'annex' || ! in_array($code, ['03', '08', '11'], true)), 422, 'This mandatory control cannot be marked not applicable.');
        DB::transaction(function () use ($request, $id, $policy, $actor, $values, $code) {
            $record = DB::table('esp_accreditations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($record, 404);
            $snapshot = $policy->accreditation($id);
            if ($values['category'] === 'stages' && $values['status'] === 'passed') {
                abort_unless(collect($snapshot['annex'])->every('passed'), 409, 'Complete and verify Annex A before progressing through the stages.');
                foreach ($snapshot['stages'] as $stage) if ((int) $stage['code'] < (int) $code) abort_unless($stage['passed'], 409, 'Prior mandatory stage must pass before progression.');
            }
            DB::table('esp_accreditation_controls')->where('accreditation_id', $id)->where('category', $values['category'])->where('code', $code)->update(['status' => $values['status'], 'evidence_reference' => $values['evidence_reference'], 'verified_by' => (string) $actor->id, 'verified_at' => now(), 'updated_at' => now()]);
            if ($values['status'] === 'failed') $this->invalidateProgression($id, $values['category'], $code);
            $policy->audit('esp.review.recorded', $id, (string) $actor->id, $values);
        });
        return response()->json(['message' => 'External review evidence recorded. This does not issue a BIR certificate.']);
    }

    public function certificate(Request $request, string $id)
    {
        $policy = app(BirCompliance::class); $actor = $policy->administrativeUser($request);
        abort_unless(DB::table('profiles')->where('id', $actor->id)->value('role') === 'bir', 403);
        $v = $request->validate(['certificate_number' => 'required|string|max:255', 'issued_at' => 'required|date|before_or_equal:today', 'expires_at' => 'required|date|after:issued_at', 'annual_review_at' => 'required|date|before_or_equal:today', 'configuration_reference' => 'required|string|max:255', 'evidence_reference' => 'required|string|min:8|max:2000']);
        abort_if(\Carbon\CarbonImmutable::parse($v['expires_at'])->greaterThan(\Carbon\CarbonImmutable::parse($v['issued_at'])->addYears(5)), 422, 'Validity cannot exceed five years.');
        DB::transaction(function () use ($id, $policy, $actor, $v) {
            abort_unless(DB::table('esp_accreditations')->where('id', $id)->lockForUpdate()->first(), 404);
            $snapshot = $policy->accreditation($id);
            abort_unless(collect($snapshot['annex'])->every('passed') && collect($snapshot['stages'])->every('passed'), 409, 'All documentary controls and mandatory stages must pass before recording accreditation.');
            DB::table('esp_accreditations')->where('id', $id)->update([...$v, 'status' => 'accredited', 'verified_at' => now(), 'verified_by' => (string) $actor->id, 'updated_at' => now()]);
            $policy->audit('esp.certificate.recorded', $id, (string) $actor->id, $v);
        });
        return response()->json(['message' => 'Existing BIR certificate evidence recorded.']);
    }

    public function incident(Request $request)
    {
        $policy = app(BirCompliance::class); $actor = $policy->administrativeUser($request);
        $v = $request->validate(['title' => 'required|string|max:255', 'severity' => 'required|in:critical,high,medium,low', 'detected_at' => 'required|date|before_or_equal:now', 'evidence_reference' => 'required|string|min:8|max:2000', 'merchant_id' => 'nullable|exists:merchants,id']);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $v, $actor, $policy) {
            $v['detected_at'] = \Carbon\CarbonImmutable::parse($v['detected_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            DB::table('eis_operational_incidents')->insert(['id' => $id, ...$v, 'status' => 'open', 'actor_id' => (string) $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $policy->audit('eis.incident.recorded', $id, (string) $actor->id, $v);
        });
        return response()->json(['id' => $id, 'report_due_at' => $v['severity'] === 'critical' ? \Carbon\CarbonImmutable::parse($v['detected_at'])->addHours(24)->toIso8601String() : null, 'message' => 'Incident recorded. Written BIR reporting must be completed by authorized personnel.'], 201);
    }

    public function updateIncident(Request $request, string $id)
    {
        $policy = app(BirCompliance::class); $actor = $policy->administrativeUser($request);
        $v = $request->validate(['restored_at' => 'nullable|date|before_or_equal:now', 'reported_at' => 'nullable|date|before_or_equal:now', 'report_reference' => 'required_with:reported_at|nullable|string|min:8|max:2000']);
        DB::transaction(function () use ($id, $v, $policy, $actor) {
            $row = DB::table('eis_operational_incidents')->where('id', $id)->lockForUpdate()->first(); abort_unless($row, 404);
            foreach (['restored_at', 'reported_at'] as $key) if (! empty($v[$key])) {
                $at = \Carbon\CarbonImmutable::parse($v[$key]);
                abort_if($at->lessThan(\Carbon\CarbonImmutable::parse($row->detected_at)), 422, 'Event cannot precede detection.');
                $v[$key] = $at->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            }
            $v = array_filter($v, fn ($value) => $value !== null);
            if (! empty($v['restored_at'])) $v['status'] = 'restored';
            DB::table('eis_operational_incidents')->where('id', $id)->update([...$v, 'updated_at' => now()]);
            $policy->audit('eis.incident.updated', $id, (string) $actor->id, ['before' => (array) $row, 'after' => $v]);
        });
        return response()->json(['message' => 'Incident evidence updated; original detection and reporting deadline retained.']);
    }
}
