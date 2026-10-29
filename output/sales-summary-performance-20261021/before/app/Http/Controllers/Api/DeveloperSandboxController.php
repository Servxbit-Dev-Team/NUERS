<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IntegrationContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeveloperSandboxController extends Controller
{
    public function run(Request $r)
    {
        abort_if(strlen($r->getContent()) > 65536, 413, 'Sandbox requests must not exceed 64 KB.');
        abort_if($r->bearerToken() || $r->header('X-NUERS-API-Key'), 422, 'The public sandbox accepts no credentials. Remove Authorization and API-key headers.');
        $v = $r->validate(['is_test' => 'required|accepted', 'action' => 'required|in:validate,preview,scenario,ingestion', 'scenario' => 'nullable|in:duplicate,conflict,unauthorized,forbidden,rate_limit,timeout,production_gate', 'seller_vat_registered' => 'required_if:action,ingestion|boolean', 'payload' => 'required_unless:action,scenario|array']);
        $base = ['is_test' => true, 'environment' => 'isolated_preview', 'persisted' => false, 'bir_contacted' => false, 'bir_status' => 'not_submitted', 'contract_version' => 'nuers-integration-2026-10-15'];
        if ($v['action'] === 'scenario') {
            $scenario = $v['scenario'] ?? 'production_gate';
            $result = match ($scenario) {
                'duplicate' => [200, ['duplicate' => true, 'transaction_id' => 'TEST-EXAMPLE-ORIGINAL', 'message' => 'Illustrative duplicate: use the existing transaction.']],
                'conflict' => [409, ['message' => 'Illustrative changed-record conflict. Reload the relevant revision.']],
                'unauthorized' => [401, ['message' => 'Illustrative missing or invalid credential.']],
                'forbidden' => [403, ['message' => 'Illustrative scope or IP policy denial.']],
                'rate_limit' => [429, ['message' => 'Illustrative quota denial. Follow Retry-After when provided; otherwise use bounded backoff.']],
                'timeout' => [504, ['message' => 'Illustrative unknown delivery outcome. Reconcile before replay.']],
                default => [409, ['message' => 'Live BIR transport is unavailable. Verified accreditation, taxpayer authorities and certification are separate gates.']]
            };

            return response()->json($base + ['scenario' => $scenario, 'simulated_http_status' => $result[0], 'response' => $result[1]])->header('Cache-Control', 'no-store');
        }
        $payload = $v['payload'] ?? [];
        $sensitive = [];
        $scan = function ($x) use (&$scan, &$sensitive) {
            foreach ($x as $k => $value) {
                if (preg_match('/secret|password|token|api.?key|private.?key|^email$/i', (string) $k)) {
                    $sensitive[] = $k;
                }if (is_array($value)) {
                    $scan($value);
                }
            }
        };
        $scan($payload);
        abort_if(count($sensitive), 422, 'Do not submit credentials, private keys or email addresses to the sandbox.');
        abort_unless(preg_match('/^(TEST|DEMO|SAMPLE)-/i', (string) ($payload['external_reference'] ?? '')), 422, 'Use an external_reference beginning with TEST-, DEMO- or SAMPLE-.');
        abort_if(! empty($payload['customer_tin']) && $payload['customer_tin'] !== '999-999-999-000' && $payload['customer_tin'] !== '999999999000', 422, 'Only the synthetic example TIN is accepted in the public sandbox.');
        if (!empty($payload['sales_benefit'])) {
            foreach (['id_number', 'beneficiary_name', 'booklet_reference'] as $field) {
                $value = $payload['sales_benefit'][$field] ?? '';
                abort_if($value !== '' && !preg_match('/^(TEST|DEMO|SAMPLE|SYNTHETIC)[ -]/i', (string)$value), 422, 'Use TEST-, DEMO- or SAMPLE- synthetic beneficiary details in the public preview.');
            }
        }
        $contract = app(IntegrationContract::class);
        $validator = Validator::make($payload, $contract->rules($v['action'] === 'ingestion'));
        if ($validator->fails()) {
            return response()->json($base + ['status' => 'invalid', 'message' => 'Request does not satisfy the NUERS transaction field contract.', 'errors' => $validator->errors()], 422)->header('Cache-Control', 'no-store');
        }
        $p = $v['action'] === 'ingestion' ? $contract->validateEvent($payload) : $validator->validated();
        if ($v['action'] === 'ingestion') {
            $tax = $contract->calculateDocument($p, (bool) $v['seller_vat_registered']);
            $resolved = $contract->resolveDocument($p, $tax);

            return response()->json($base + ['status' => 'valid', 'document_type' => $resolved['type'], 'schema_version' => 'NUERS-ingestion-1', 'calculation' => $tax, 'warnings' => ['Synthetic field, document-family and line reconciliation preview only.', 'No wallet debit, authority check, sequence allocation or financial record is created.', 'Use the reviewed seller VAT classification in production; preview registration is synthetic.']])->header('Cache-Control', 'no-store');
        }
        $amount = (float) ($p['gross_amount'] ?? $p['amount'] ?? 0);
        abort_if($amount > 1000000000, 422, 'Sandbox amount exceeds the preview limit.');
        $tax = $contract->computeTax($amount, $p['tax_type'], (float) ($p['vat_rate'] ?? 12), (bool) ($p['vat_inclusive'] ?? true));

        return response()->json($base + ['status' => 'valid', 'simulated_http_status' => $v['action'] === 'preview' ? 201 : 200, 'external_reference' => $p['external_reference'], 'calculation' => $tax, 'warnings' => ['NUERS field validation and calculation preview only; not official BIR schema conformance.', 'Line items are accepted as an array by this endpoint; the current contract does not reconcile per-line totals.', 'The real transaction endpoint requires a merchant API key and successful taxpayer issuance gates.', 'Preview results do not issue an invoice, grant authority, create a queue entry or validate a live BIR connection.']])->header('Cache-Control', 'no-store');
    }
}
