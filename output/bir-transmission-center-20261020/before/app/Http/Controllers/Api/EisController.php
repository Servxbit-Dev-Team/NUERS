<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BirCompliance;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class EisController extends Controller
{
    public function readiness(Request $request): JsonResponse
    {
        app(BirCompliance::class)->administrativeUser($request);
        $integration = $this->integration();
        $checks = DB::table('eis_readiness_checks')
            ->where('integration_id', $integration?->id)
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderBy('category')
            ->orderBy('control_code')
            ->get();

        $readyChecks = $checks->where('status', 'ready')->count();
        $totalChecks = max($checks->count(), 1);
        $criticalBlockers = $checks->where('severity', 'critical')->where('status', '!=', 'ready')->count();
        $readinessScore = (int) round(($readyChecks / $totalChecks) * 100);
        $gate = app(BirCompliance::class)->gate($integration);
        $productionBlocked = $criticalBlockers > 0 || ! $gate['can_transmit'];

        $status = $productionBlocked
            ? 'production_blocked'
            : ($readinessScore >= 90 ? 'production_ready' : 'certification_pending');

        $transmissionCounts = DB::table('eis_transmissions')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $totalTransmissions = (int) $transmissionCounts->sum();
        $acknowledged = (int) ($transmissionCounts['acknowledged'] ?? 0);
        $failed = (int) (($transmissionCounts['failed'] ?? 0) + ($transmissionCounts['rejected'] ?? 0));
        $queued = (int) (($transmissionCounts['queued'] ?? 0) + ($transmissionCounts['submitting'] ?? 0) + ($transmissionCounts['submitted'] ?? 0));
        $successRate = $totalTransmissions > 0 ? round(($acknowledged / $totalTransmissions) * 100, 2) : 0.0;

        return response()->json([
            'integration' => $this->formatIntegration($integration),
            'readiness' => [
                'score' => $readinessScore,
                'status' => $status,
                'critical_blockers' => $criticalBlockers,
                'production_blocked' => $productionBlocked,
                'can_submit_to_bir' => ! $productionBlocked,
                'deadline_days' => 3,
                'production_blockers' => $gate['blockers'],
                'authorization_ready' => $gate['authorization_ready'],
                'last_updated_at' => Carbon::now()->toIso8601String(),
            ],
            'kpis' => [
                ['key' => 'transmissions', 'label' => 'EIS Transmissions', 'value' => $this->compactNumber($totalTransmissions), 'subtext' => "{$queued} queued, {$failed} failed/rejected", 'status' => 'database'],
                ['key' => 'payloads', 'label' => 'Validated Payloads', 'value' => $this->compactNumber($this->count('eis_invoice_payloads')), 'subtext' => 'Canonical JSON hash stored', 'status' => 'database'],
                ['key' => 'success_rate', 'label' => 'Recorded Acknowledgements', 'value' => number_format($successRate, 2).'%', 'subtext' => "{$acknowledged} recorded (includes certification tests)", 'status' => $successRate >= 95 ? 'ready' : 'monitor'],
                ['key' => 'production_gate', 'label' => 'Production Gate', 'value' => $productionBlocked ? 'Blocked' : 'Ready', 'subtext' => $productionBlocked ? 'Certification/PTT/credentials required' : 'Credentials and controls registered', 'status' => $productionBlocked ? 'blocked' : 'ready'],
            ],
            'checks' => $checks->map(fn ($row) => [
                'id' => $row->id,
                'category' => $row->category,
                'control_code' => $row->control_code,
                'name' => $row->name,
                'requirement' => $row->requirement,
                'status' => $row->status,
                'severity' => $row->severity,
                'evidence' => $row->evidence,
                'source_reference' => $row->source_reference,
                'remediation' => $row->remediation,
                'last_checked_at' => $row->last_checked_at,
            ])->values(),
            'services' => DB::table('eis_service_health_checks')
                ->orderByRaw("CASE status WHEN 'pending_credentials' THEN 1 WHEN 'outage' THEN 2 WHEN 'degraded' THEN 3 ELSE 4 END")
                ->orderBy('service_name')
                ->get()
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'service_name' => $row->service_name,
                    'status' => $row->status,
                    'latency_ms' => $row->latency_ms === null ? null : (int) $row->latency_ms,
                    'uptime_percentage' => (float) $row->uptime_percentage,
                    'checked_at' => $row->checked_at,
                    'metadata' => json_decode($row->metadata ?? '{}', true) ?: [],
                ])->values(),
            'recent_transmissions' => $this->transmissionQuery()
                ->limit(8)
                ->get()
                ->map(fn ($row) => $this->formatTransmission($row))
                ->values(),
            'charts' => [
                'hourly' => $this->hourlyTransmissionSeries(),
                'weekly' => $this->weeklyTransmissionSeries(),
            ],
            'requirements' => [
                ['title' => 'ESP accreditation, taxpayer PTI and PTT', 'detail' => 'Solution/version accreditation, taxpayer PTI, EIS certification, PTT, written authorization and verified BIR transport are separate production gates.'],
                ['title' => 'Encrypted JSON sales data', 'detail' => 'NUERS stores canonical JSON payloads and hashes; final production encryption uses BIR-issued key material.'],
                ['title' => 'Three-calendar-day SLA', 'detail' => 'Payload validation calculates a due date and flags records older than the allowed transmission window.'],
                ['title' => 'Duplicate and retry controls', 'detail' => 'Every transmission has a SHA-256 payload hash, duplicate reference, attempt count, and retry schedule.'],
            ],
        ]);
    }

    public function transmissions(Request $request): JsonResponse
    {
        app(BirCompliance::class)->administrativeUser($request);
        $query = $this->transmissionQuery();

        if ($request->filled('status') && $request->string('status')->value() !== 'all') {
            $query->where('t.status', $request->string('status')->value());
        }

        if ($request->filled('type') && $request->string('type')->value() !== 'all') {
            $query->where('t.transmission_type', $request->string('type')->value());
        }

        if ($request->filled('tab')) {
            match ($request->string('tab')->value()) {
                'pending' => $query->whereIn('t.status', ['queued', 'submitting', 'submitted']),
                'failed' => $query->whereIn('t.status', ['failed', 'rejected']),
                'retries' => $query->where('t.attempt_number', '>', 1),
                default => null,
            };
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->value().'%';
            $query->where(function ($inner) use ($search) {
                $inner->where('t.id', 'like', $search)
                    ->orWhere('t.payload_hash', 'like', $search)
                    ->orWhere('t.bir_reference_number', 'like', $search)
                    ->orWhere('m.business_name', 'like', $search)
                    ->orWhere('m.tin', 'like', $search)->orWhere('p.invoice_number', 'like', $search)->orWhere('t.bir_acknowledgement', 'like', $search);
            });
        }

        $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'environment' => 'nullable|in:all,production,certification,sandbox', 'document' => 'nullable|string|max:80']);
        if ($request->filled('from')) {
            $query->where('t.created_at', '>=', $request->input('from').' 00:00:00');
        }
        if ($request->filled('to')) {
            $query->where('t.created_at', '<=', $request->input('to').' 23:59:59');
        }
        if ($request->filled('environment') && $request->input('environment') !== 'all') {
            $query->where('t.environment', $request->input('environment'));
        }
        if ($request->filled('document') && $request->input('document') !== 'all') {
            $query->where('p.document_type', $request->input('document'));
        }
        $total = (clone $query)->count();
        $scope = clone $query;
        $perPage = (int) $request->input('per_page', 100);
        $transmissions = $query->offset(((int) $request->input('page', 1) - 1) * $perPage)->limit($perPage)->get()->map(fn ($row) => $this->formatTransmission($row))->values();
        $counts = DB::query()->fromSub((clone $scope)->reorder(), 'filtered')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'summary' => [
                'total' => (int) $counts->sum(),
                'transmitted' => (int) (($counts['submitted'] ?? 0) + ($counts['acknowledged'] ?? 0) + ($counts['recovered'] ?? 0)),
                'acknowledged' => (int) ($counts['acknowledged'] ?? 0),
                'failed' => (int) (($counts['failed'] ?? 0) + ($counts['rejected'] ?? 0)),
                'queued' => (int) (($counts['queued'] ?? 0) + ($counts['submitting'] ?? 0) + ($counts['submitted'] ?? 0)),
                'duplicates' => (int) ($counts['duplicate'] ?? 0),
                'retry_queue' => DB::table('eis_retry_queue')->where('result', 'scheduled')->whereIn('transmission_id', (clone $scope)->reorder()->select('t.id'))->count(),
            ],
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'generated_at' => now()->toIso8601String(),
            'trend' => DB::query()->fromSub((clone $scope)->reorder(), 'filtered')->selectRaw('DATE(created_at) as date, COUNT(*) as total')->groupByRaw('DATE(created_at)')->orderByDesc('date')->limit(7)->get()->reverse()->values(),
            'environments' => DB::table('eis_transmissions')->select('environment')->selectRaw('COUNT(*) as total')->groupBy('environment')->get(),
            'transmissions' => $transmissions,
            'retry_queue' => DB::table('eis_retry_queue as r')
                ->join('eis_transmissions as t', 't.id', '=', 'r.transmission_id')
                ->where('r.result', 'scheduled')
                ->orderBy('r.scheduled_at')
                ->limit(25)
                ->get([
                    'r.id',
                    'r.transmission_id',
                    't.transmission_type',
                    't.invoice_count',
                    'r.attempt_number',
                    't.max_attempts',
                    'r.scheduled_at',
                    'r.error_message',
                ]),
        ]);
    }

    public function validatePayload(Request $request): JsonResponse
    {
        app(BirCompliance::class)->administrativeUser($request);
        [$payload, $canonical, $hash] = $this->payloadFromRequest($request);
        $result = $this->validateEisPayload($payload);

        return response()->json([
            'status' => empty($result['errors']) ? 'valid' : 'invalid',
            'validation_scope'=>'local_preflight_only',
            'official_schema_verified'=>false,
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
            'invoice_count' => $this->payloadInvoiceCount($payload),
            'payload_hash' => $hash,
            'payload_size' => strlen($canonical),
            'due_at' => $result['due_at'],
        ], empty($result['errors']) ? 200 : 422);
    }

    public function storeTransmission(Request $request): JsonResponse
    {
        app(BirCompliance::class)->administrativeUser($request);
        [$payload, $canonical, $hash] = $this->payloadFromRequest($request);
        $validation = $this->validateEisPayload($payload);

        if (! empty($validation['errors'])) {
            return response()->json([
                'message' => 'EIS payload validation failed.',
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
                'payload_hash' => $hash,
            ], 422);
        }

        $merchant = $this->merchantForPayload($payload);
        $integration = $merchant ? app(BirCompliance::class)->integration((string) $merchant->id) : null;
        $environment = (string) $request->input('environment', 'certification');
        abort_unless(in_array($environment, ['certification', 'production'], true), 422, 'Unknown EIS environment.');
        if ($environment === 'production') {
            abort_unless($merchant && $integration, 409, 'A matching taxpayer and its own EIS integration are required.');
            $gate = app(BirCompliance::class)->gate($integration);
            abort_unless($gate['can_transmit'], 409, implode(' ', $gate['blockers']));
        } else {
            abort_unless(($payload['is_test'] ?? false) === true, 422, 'Certification accepts explicitly marked synthetic test data only (is_test: true). It is not authorized for live taxpayer transactions.');
        }
        $sellerTin = preg_replace('/\D/', '', (string) ($payload['seller_tin'] ?? data_get($payload, 'seller.tin') ?? $payload['merchant_tin'] ?? data_get($payload, 'merchant.tin')));
        $identity = hash('sha256', implode('|', [$sellerTin, $payload['branch_code'] ?? $integration?->branch_code ?? '00000', strtolower($payload['document_type'] ?? $payload['type'] ?? 'invoice'), strtoupper(trim($this->invoiceNumber($payload)))]));
        $duplicate = DB::table('eis_transmissions')
            ->where('document_identity_hash', $identity)
            ->where('environment', $environment)
            ->orderByDesc('created_at')
            ->first();

        if ($duplicate) {
            abort_unless(hash_equals($duplicate->payload_hash, $hash), 409, 'This document identity already exists with different content. Preserve the original and use a traceable correction document.');

            return response()->json(['duplicate' => true, 'message' => 'Existing transmission returned; no second document or retry was created.', 'transmission' => $this->formatTransmission($this->transmissionQuery()->where('t.id', $duplicate->id)->first())]);
        }
        $payloadId = (string) Str::uuid();
        $transmissionId = (string) Str::uuid();
        $now = Carbon::now();
        $status = $duplicate ? 'duplicate' : 'queued';
        $responseCode = $duplicate ? 'NUERS-DUPLICATE' : 'NUERS-CREDENTIALS-PENDING';
        $responseMessage = $duplicate
            ? 'Duplicate payload hash detected; transmission references the original queued/submitted record.'
            : 'Validated and queued inside NUERS. Certification test queue only. Production requires verified ESP accreditation, taxpayer PTI, written authority, EIS certification, PTT and verified BIR transport.';

        try {
            DB::transaction(function () use ($payload, $canonical, $hash, $validation, $integration, $duplicate, $merchant, $payloadId, $transmissionId, $now, $status, $responseCode, $responseMessage, $request, $identity, $environment) {
                DB::table('eis_invoice_payloads')->insert([
                    'id' => $payloadId,
                    'merchant_id' => $merchant?->id,
                    'receipt_id' => $payload['receipt_id'] ?? null,
                    'invoice_number' => $this->invoiceNumber($payload),
                    'document_type' => $payload['document_type'] ?? $payload['type'] ?? 'invoice',
                    'issue_date' => $this->issueDate($payload)?->toDateString(),
                    'due_at' => $validation['due_at'],
                    'payload' => json_encode(['encrypted' => Crypt::encryptString($canonical)]),
                    'payload_hash' => $hash,
                    'payload_size' => strlen($canonical),
                    'validation_status' => 'valid',
                    'validation_errors' => json_encode($validation),
                    'signature_algorithm' => 'SHA256',
                    'digital_signature_hash' => $payload['digital_signature_hash'] ?? $payload['signature_hash'] ?? null,
                    'encrypted_payload_hash' => $payload['encrypted_payload_hash'] ?? null,
                    'source' => $request->input('source', 'manual'),
                    'status' => 'ready_for_queue',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('eis_transmissions')->insert([
                    'id' => $transmissionId,
                    'integration_id' => $integration?->id,
                    'merchant_id' => $merchant?->id,
                    'payload_id' => $payloadId,
                    'transmission_type' => $request->input('transmission_type', 'invoice_submission'),
                    'status' => $status,
                    'invoice_count' => $this->payloadInvoiceCount($payload),
                    'payload_hash' => $hash,
                    'duplicate_of' => $duplicate?->id,
                    'response_code' => $responseCode,
                    'response_message' => $responseMessage,
                    'attempt_number' => 1,
                    'max_attempts' => 5,
                    'next_retry_at' => $duplicate ? null : $now->copy()->addMinutes(15),
                    'due_at' => $validation['due_at'],
                    'environment' => $environment,
                    'document_identity_hash' => $identity,
                    'api_version' => $request->input('api_version', $integration?->api_version ?? 'v2'),
                    'endpoint_url' => 'nuers:certification-simulator',
                    'request_id' => (string) Str::uuid(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('eis_transmission_payloads')->insert([
                    'id' => (string) Str::uuid(),
                    'transmission_id' => $transmissionId,
                    'payload_format' => 'encrypted_json',
                    'payload' => Crypt::encryptString($canonical),
                    'payload_size' => strlen($canonical),
                    'payload_hash' => $hash,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if (! $duplicate) {
                    DB::table('eis_retry_queue')->insert([
                        'id' => (string) Str::uuid(),
                        'transmission_id' => $transmissionId,
                        'merchant_id' => $merchant?->id,
                        'attempt_number' => 1,
                        'scheduled_at' => $now->copy()->addMinutes(15),
                        'result' => 'scheduled',
                        'error_message' => 'Waiting for production EIS credentials/certification gate.',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $this->logApiRequest($integration?->id, $transmissionId, $request, 202, $responseCode, $responseMessage, strlen($canonical));
                $this->audit('eis.transmission.queued', 'eis_transmissions', $transmissionId, $request, [
                    'payload_hash' => $hash,
                    'status' => $status,
                    'duplicate_of' => $duplicate?->id,
                ]);
            });

        } catch (QueryException $exception) {
            // The unique identity constraint resolves concurrent submissions after rollback.
            $existing = DB::table('eis_transmissions')->where('environment', $environment)->where('document_identity_hash', $identity)->first();
            if (! $existing) {
                throw $exception;
            }
            abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'This document identity already exists with different content. Preserve the original and use a traceable correction document.');

            return response()->json(['duplicate' => true, 'message' => 'Existing concurrent transmission returned.', 'transmission' => $this->formatTransmission($this->transmissionQuery()->where('t.id', $existing->id)->first())]);
        }

        $row = $this->transmissionQuery()->where('t.id', $transmissionId)->first();

        return response()->json([
            'message' => $responseMessage,
            'transmission' => $this->formatTransmission($row),
            'validation' => $validation,
        ], 202);
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        $policy = app(BirCompliance::class);
        $actor = $policy->administrativeUser($request);
        DB::transaction(function () use ($request, $id, $policy, $actor) {
            $t = DB::table('eis_transmissions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($t, 404, 'EIS transmission not found.');
            abort_unless(in_array($t->status, ['failed', 'queued', 'recovered'], true), 409, 'Acknowledged, in-flight, rejected, duplicate and cancelled records cannot be blindly retransmitted.');
            if ($t->environment === 'production') {
                $gate = $policy->gate(DB::table('eis_integrations')->where('id', $t->integration_id)->first());
                abort_unless($gate['can_transmit'], 409, implode(' ', $gate['blockers']));
            }
            if ($t->status === 'queued' && DB::table('eis_retry_queue')->where('transmission_id', $id)->where('result', 'scheduled')->exists()) {
                return;
            }
            abort_if((int) $t->attempt_number >= (int) $t->max_attempts, 409, 'Retry limit reached. Preserve the record for investigation and reconciliation.');
            abort_if(preg_match('/^(400|401|403|422)/', $t->response_code ?? ''), 409, 'Correct the validation or authorization failure before retrying.');
            $attempt = (int) $t->attempt_number + 1;
            $scheduled = now()->addMinutes(min(60, 2 ** $attempt));
            $recoveryDue = $t->recovery_due_at ?? null;
            $incidentId = $t->incident_id ?? null;
            if ($request->filled('incident_id')) {
                $incident = DB::table('eis_operational_incidents')->where('id', $request->string('incident_id')->value())->first();
                abort_unless($incident && $incident->restored_at && (! $incident->merchant_id || $incident->merchant_id === $t->merchant_id), 422, 'A recorded restoration for this taxpayer is required.');
                $recoveryDue = $policy->deadline($incident->restored_at)->toDateTimeString();
                $incidentId = $incident->id;
            }
            DB::table('eis_retry_queue')->where('transmission_id', $id)->where('result', 'scheduled')->update(['result' => 'superseded', 'updated_at' => now()]);
            DB::table('eis_transmissions')->where('id', $id)->update(['status' => 'queued', 'attempt_number' => $attempt, 'next_retry_at' => $scheduled, 'incident_id' => $incidentId, 'recovery_due_at' => $recoveryDue, 'response_code' => 'NUERS-RETRY-SCHEDULED', 'response_message' => 'Retry queued; original transaction deadline preserved.', 'updated_at' => now()]);
            DB::table('eis_retry_queue')->insert(['id' => (string) Str::uuid(), 'transmission_id' => $id, 'merchant_id' => $t->merchant_id, 'attempt_number' => $attempt, 'scheduled_at' => $scheduled, 'result' => 'scheduled', 'error_message' => 'Administrative retry; authorization gates still apply.', 'created_at' => now(), 'updated_at' => now()]);
            $policy->audit('eis.retry.scheduled', $id, (string) $actor->id, ['attempt' => $attempt, 'scheduled_at' => $scheduled->toIso8601String(), 'original_due_at' => $t->due_at, 'recovery_due_at' => $recoveryDue]);
        });

        return response()->json(['message' => 'Retry queue checked.', 'transmission' => $this->formatTransmission($this->transmissionQuery()->where('t.id', $id)->first())]);
    }

    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $policy = app(BirCompliance::class);
        $actor = $policy->administrativeUser($request);
        DB::transaction(function () use ($id, $request, $policy, $actor) {
            $t = DB::table('eis_transmissions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($t, 404, 'EIS transmission not found.');
            abort_unless($t->environment === 'certification', 409, 'Production acknowledgement can only come from verified BIR transport, never this manual certification action.');
            abort_unless($request->boolean('is_test'), 422, 'Explicit synthetic certification acknowledgement is required.');
            if ($t->status === 'acknowledged') {
                return;
            }
            abort_if(in_array($t->status, ['duplicate', 'cancelled', 'rejected'], true), 409, 'This record cannot be acknowledged.');
            $ack = 'CERT-ACK-'.strtoupper(Str::random(8));
            $reference = 'CERT-BIR-'.now()->format('YmdHis');
            DB::table('eis_transmissions')->where('id', $id)->update(['status' => 'acknowledged', 'bir_reference_number' => $reference, 'bir_acknowledgement' => $ack, 'response_code' => 'CERT-SIMULATED', 'response_message' => 'Synthetic certification acknowledgement. Not a live BIR acceptance.', 'acknowledged_at' => now(), 'next_retry_at' => null, 'updated_at' => now()]);
            DB::table('eis_acknowledgements')->insert(['id' => (string) Str::uuid(), 'transmission_id' => $id, 'bir_reference_number' => $reference, 'acknowledgement_number' => $ack, 'response_code' => 'CERT-SIMULATED', 'response_message' => 'Synthetic test acknowledgement', 'raw_response' => json_encode(['simulated' => true]), 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('eis_retry_queue')->where('transmission_id', $id)->where('result', 'scheduled')->update(['result' => 'superseded', 'updated_at' => now()]);
            $policy->audit('eis.certification.acknowledged', $id, (string) $actor->id, ['simulated' => true, 'reference' => $reference]);
        });

        return response()->json(['message' => 'Certification simulation recorded; not a BIR production acceptance.', 'transmission' => $this->formatTransmission($this->transmissionQuery()->where('t.id', $id)->first())]);
    }

    private function integration(): ?object
    {
        return DB::table('eis_integrations')->orderByDesc('updated_at')->first();
    }

    private function formatIntegration(?object $integration): ?array
    {
        if (! $integration) {
            return null;
        }

        return [
            'id' => $integration->id,
            'merchant_id' => $integration->merchant_id,
            'merchant_name' => $integration->merchant_name,
            'taxpayer_tin' => $integration->taxpayer_tin,
            'branch_code' => $integration->branch_code,
            'environment' => $integration->environment,
            'status' => $integration->status,
            'certification_status' => $integration->certification_status,
            'ptt_status' => $integration->ptt_status,
            'endpoint_base_url' => $integration->endpoint_base_url,
            'json_schema_version' => $integration->json_schema_version,
            'api_version' => $integration->api_version,
            'eis_certificate_number' => $integration->eis_certificate_number,
            'permit_to_transmit_number' => $integration->permit_to_transmit_number,
            'credentials_present' => (bool) $integration->credentials_present,
            'transmission_deadline_days' => 3,
            'signing_algorithm' => $integration->signing_algorithm,
            'encryption_mode' => $integration->encryption_mode,
            'public_key_fingerprint' => $integration->public_key_fingerprint,
        ];
    }

    private function transmissionQuery()
    {
        return DB::table('eis_transmissions as t')
            ->leftJoin('merchants as m', 'm.id', '=', 't.merchant_id')
            ->leftJoin('eis_invoice_payloads as p', 'p.id', '=', 't.payload_id')
            ->select([
                't.*',
                'm.business_name as merchant_name',
                'm.tin as merchant_tin',
                'p.invoice_number',
                'p.document_type',
                'p.issue_date',
                'p.payload as payload_envelope',
            ])
            ->orderByDesc('t.created_at')->orderBy('t.id');
    }

    private function formatTransmission(?object $row): ?array
    {
        if (! $row) {
            return null;
        }

        $amount = null;
        try {
            $body = json_decode($row->payload_envelope ?? '{}', true);
            if (isset($body['encrypted'])) {
                $body = json_decode(Crypt::decryptString($body['encrypted']), true);
            }$v = $body['total_due'] ?? $body['gross_amount'] ?? $body['total_amount'] ?? null;
            if (is_numeric($v)) {
                $amount = (float) $v;
            }
        } catch (Throwable $e) {
        }

        return [
            'amount' => $amount,
            'id' => $row->id,
            'type' => $row->transmission_type,
            'status' => $row->status,
            'invoice_count' => (int) $row->invoice_count,
            'payload_hash' => $row->payload_hash,
            'duplicate_of' => $row->duplicate_of,
            'bir_reference_number' => $row->bir_reference_number,
            'bir_acknowledgement' => $row->bir_acknowledgement,
            'response_code' => $row->response_code,
            'response_message' => $row->response_message,
            'attempt_number' => (int) $row->attempt_number,
            'max_attempts' => (int) $row->max_attempts,
            'next_retry_at' => $row->next_retry_at ? Carbon::parse($row->next_retry_at, config('app.timezone'))->toIso8601String() : null,
            'submitted_at' => $row->submitted_at ? Carbon::parse($row->submitted_at, config('app.timezone'))->toIso8601String() : null,
            'acknowledged_at' => $row->acknowledged_at ? Carbon::parse($row->acknowledged_at, config('app.timezone'))->toIso8601String() : null,
            'due_at' => $row->due_at ? Carbon::parse($row->due_at, config('app.timezone'))->toIso8601String() : null,
            'recovery_due_at' => $row->recovery_due_at ?? null,
            'simulated' => $row->environment !== 'production',
            'latency_ms' => $row->latency_ms === null ? null : (int) $row->latency_ms,
            'environment' => $row->environment,
            'api_version' => $row->api_version,
            'endpoint_url' => $row->endpoint_url,
            'request_id' => $row->request_id,
            'merchant_name' => $row->merchant_name,
            'merchant_tin' => $row->merchant_tin,
            'invoice_number' => $row->invoice_number,
            'document_type' => $row->document_type,
            'issue_date' => $row->issue_date,
            'created_at' => $row->created_at ? Carbon::parse($row->created_at, config('app.timezone'))->toIso8601String() : null,
        ];
    }

    private function payloadFromRequest(Request $request): array
    {
        $rawPayload = $request->input('payload', $request->all());

        if (is_string($rawPayload)) {
            $decoded = json_decode($rawPayload, true);
            abort_if(json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded), 422, 'Payload must be valid JSON.');
            $payload = $decoded;
        } elseif (is_array($rawPayload)) {
            $payload = $rawPayload;
        } else {
            abort(422, 'Payload must be valid JSON.');
        }

        $canonicalPayload = $this->canonicalJson($payload);

        return [$payload, $canonicalPayload, hash('sha256', $canonicalPayload)];
    }

    private function validateEisPayload(array $payload): array
    {
        $errors = [];
        $warnings = [];

        if (! $this->invoiceNumber($payload)) {
            $errors[] = 'Missing invoice_number, receipt_number, or document_id.';
        }

        $issueDate = $this->issueDate($payload);
        if (! $issueDate) {
            $errors[] = 'Missing or invalid issue_date / transaction_date.';
        }

        $sellerTin = $payload['seller_tin']
            ?? data_get($payload, 'seller.tin')
            ?? data_get($payload, 'merchant.tin')
            ?? $payload['merchant_tin']
            ?? null;

        if (! $sellerTin || ! $this->tinIsValid((string) $sellerTin)) {
            $errors[] = 'Seller or merchant TIN is required and must follow a valid Philippine TIN format.';
        }

        $total = $payload['total_due']
            ?? $payload['gross_amount']
            ?? $payload['total_amount']
            ?? data_get($payload, 'totals.total_due')
            ?? null;

        if (! is_numeric($total) || (float) $total <= 0) {
            $errors[] = 'A positive total_due, gross_amount, or total_amount is required.';
        }

        if (! ($payload['document_type'] ?? $payload['type'] ?? null)) {
            $warnings[] = 'Document type is not set; NUERS will default to invoice_submission.';
        }

        if (! ($payload['digital_signature_hash'] ?? $payload['signature_hash'] ?? null)) {
            $warnings[] = 'Digital signature hash is not present yet; production transmission must be signed.';
        }

        if (! ($payload['encrypted_payload_hash'] ?? null)) {
            $warnings[] = 'Encrypted payload hash is not present yet; production transmission must use encrypted JSON sales data.';
        }

        $dueAt = null;
        if ($issueDate) {
            $dueAt = app(BirCompliance::class)->deadline($issueDate->toIso8601String());
            if ($dueAt->isPast()) {
                $warnings[] = 'Issue date is older than the BIR EIS three-calendar-day transmission window.';
            }
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'due_at' => $dueAt?->toDateTimeString(),
        ];
    }

    private function invoiceNumber(array $payload): ?string
    {
        return $payload['invoice_number']
            ?? $payload['receipt_number']
            ?? $payload['document_id']
            ?? data_get($payload, 'invoice.number')
            ?? null;
    }

    private function issueDate(array $payload): ?CarbonImmutable
    {
        $date = $payload['issue_date']
            ?? $payload['transaction_date']
            ?? $payload['issued_at']
            ?? data_get($payload, 'invoice.issue_date')
            ?? null;

        if (! $date) {
            return null;
        }

        try {
            return CarbonImmutable::parse($date);
        } catch (Throwable) {
            return null;
        }
    }

    private function payloadInvoiceCount(array $payload): int
    {
        $invoices = $payload['invoices'] ?? $payload['documents'] ?? null;
        if (is_array($invoices)) {
            return max(count($invoices), 1);
        }

        return 1;
    }

    private function tinIsValid(string $tin): bool
    {
        return (bool) preg_match('/^\d{3}-?\d{3}-?\d{3}(-?\d{3,5})?$/', trim($tin));
    }

    private function merchantForPayload(array $payload): ?object
    {
        $sellerTin = $payload['seller_tin']
            ?? data_get($payload, 'seller.tin')
            ?? data_get($payload, 'merchant.tin')
            ?? $payload['merchant_tin']
            ?? null;

        if (! $sellerTin || ! Schema::hasTable('merchants')) {
            return null;
        }

        $tin = preg_replace('/[^0-9]/', '', (string) $sellerTin);

        return DB::table('merchants')
            ->whereRaw("REPLACE(REPLACE(tin, '-', ''), ' ', '') = ?", [$tin])
            ->first();
    }

    private function canonicalJson(array $payload): string
    {
        $normalized = $this->sortKeys($payload);

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->sortKeys($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->sortKeys($item), $value);
    }

    private function hourlyTransmissionSeries(): array
    {
        $rows = DB::table('eis_transmissions')
            ->selectRaw('SUBSTR(created_at, 1, 13) as label')
            ->selectRaw('COUNT(*) as submitted')
            ->selectRaw("SUM(CASE WHEN status = 'acknowledged' THEN 1 ELSE 0 END) as acknowledged")
            ->selectRaw("SUM(CASE WHEN status IN ('failed', 'rejected') THEN 1 ELSE 0 END) as failed")
            ->whereBetween('created_at', [Carbon::now()->subDay(), Carbon::now()])
            ->groupBy('label')
            ->orderBy('label')
            ->get();

        return $rows->map(fn ($row) => [
            'label' => strlen($row->label) === 13 ? Carbon::parse($row->label.':00:00')->format('H:00') : Carbon::parse($row->label)->format('D'),
            'submitted' => (int) $row->submitted,
            'acknowledged' => (int) $row->acknowledged,
            'failed' => (int) $row->failed,
        ])->values()->all();
    }

    private function weeklyTransmissionSeries(): array
    {
        $rows = DB::table('eis_transmissions')
            ->selectRaw('SUBSTR(created_at, 1, 10) as label')
            ->selectRaw('COUNT(*) as submitted')
            ->selectRaw("SUM(CASE WHEN status = 'acknowledged' THEN 1 ELSE 0 END) as acknowledged")
            ->selectRaw("SUM(CASE WHEN status IN ('failed', 'rejected') THEN 1 ELSE 0 END) as failed")
            ->whereBetween('created_at', [Carbon::now()->subDays(7), Carbon::now()])
            ->groupBy('label')
            ->orderByRaw('MIN(created_at)')
            ->get();

        return $rows->map(fn ($row) => [
            'label' => strlen($row->label) === 13 ? Carbon::parse($row->label.':00:00')->format('H:00') : Carbon::parse($row->label)->format('D'),
            'submitted' => (int) $row->submitted,
            'acknowledged' => (int) $row->acknowledged,
            'failed' => (int) $row->failed,
        ])->values()->all();
    }

    private function count(string $table, array $where = []): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        return (int) $query->count();
    }

    private function compactNumber(int $value): string
    {
        if ($value >= 1_000_000) {
            return round($value / 1_000_000, 1).'M';
        }

        if ($value >= 1_000) {
            return round($value / 1_000, 1).'K';
        }

        return (string) $value;
    }

    private function logApiRequest(?string $integrationId, ?string $transmissionId, Request $request, int $statusCode, ?string $errorCode, ?string $message, int $requestSize): void
    {
        if (! Schema::hasTable('eis_api_request_logs')) {
            return;
        }

        DB::table('eis_api_request_logs')->insert([
            'id' => (string) Str::uuid(),
            'integration_id' => $integrationId,
            'transmission_id' => $transmissionId,
            'method' => $request->method(),
            'endpoint' => '/api/bir-eis/transmissions',
            'status_code' => $statusCode,
            'latency_ms' => null,
            'request_size' => $requestSize,
            'response_size' => $message ? strlen($message) : 0,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'error_code' => $errorCode,
            'error_message' => $message,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function audit(string $eventType, ?string $auditableType, ?string $auditableId, Request $request, array $metadata): void
    {
        $actor = $request->bearerToken() ? app(\App\Services\ApiAccess::class)->user($request)?->id : null;
        app(BirCompliance::class)->audit($eventType, $auditableId, (string) ($actor ?? 'system'), $metadata);
        if (! Schema::hasTable('audit_events')) {
            return;
        }

        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(),
            'actor_id' => null,
            'actor_email' => null,
            'event_type' => $eventType,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'ip_address' => $request->ip(),
            'device_fingerprint' => null,
            'severity' => 'info',
            'metadata' => json_encode($metadata),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }
}
