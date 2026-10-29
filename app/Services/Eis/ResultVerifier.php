<?php

namespace App\Services\Eis;

use RuntimeException;

final class ResultVerifier
{
    public function identity(array $data, object $profile, string $submitId, ?string $ackId = null): void
    {
        if (($data['accreditationId'] ?? null) !== $profile->accreditation_id || ($data['userId'] ?? null) !== $profile->user_id || ($data['refSubmitId'] ?? null) !== $submitId || ! preg_match('/^BIR-[0-9]{14}-[A-Z0-9]{5}$/D', (string) ($data['ackId'] ?? '')) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D', (string) ($data['responseDtm'] ?? ''))) {
            throw new RuntimeException('BIR response identity does not match the submitted batch.');
        }
        if ($ackId && ! hash_equals($ackId, $data['ackId'])) {
            throw new RuntimeException('BIR acknowledgment identity changed; reconcile before continuing.');
        }
    }

    public function verify(array $data, object $profile, string $submitId, array $expectedUids, ?string $ackId = null): array
    {
        $this->identity($data, $profile, $submitId, $ackId);
        if (($data['processStatusCode'] ?? null) === '02') {
            return ['state' => 'processing', 'documents' => []];
        }
        if (($data['processStatusCode'] ?? null) === '03') {
            return ['state' => 'reconciliation', 'documents' => [], 'error_code' => $data['failReasonStatusCode'] ?? 'UNKNOWN'];
        }
        if (($data['processStatusCode'] ?? null) !== '01') {
            throw new RuntimeException('Unknown BIR processing status.');
        }
        // Public examples contain spelling inconsistencies. Select one documented alias; never merge conflicting lists.
        $keys = array_values(array_intersect(['processedDocuments', 'processDocuments', 'proccessdDocuments'], array_keys($data)));
        if (count($keys) !== 1 || ! is_array($data[$keys[0]])) {
            throw new RuntimeException('Missing or ambiguous BIR document result list.');
        }
        $rows = $data[$keys[0]];
        $uids = array_column($rows, 'invoiceUid');
        $expected = $expectedUids;
        sort($expected);
        $actual = $uids;
        sort($actual);
        if ($actual !== $expected || count(array_unique($uids)) !== count($uids) || ($data['totalCountQuantity'] ?? null) !== count($expected)) {
            throw new RuntimeException('BIR result must contain every expected invoice exactly once.');
        }
        $accepted = 0;
        $failureCodes = ['SYN002', 'SYN003', 'SYN004', 'ERR001', 'ERR002', 'ERR003', 'ERR004', 'ERR005', 'ERR006', 'ERR007', 'ERR011', 'ERR012', 'ERR013', 'ERR014', 'ERR015', 'ERR999'];
        foreach ($rows as $row) {
            $code = $row['resultStatusCode'] ?? '';
            if ($code === 'SUC001') {
                $accepted++;
            } elseif (! in_array($code, $failureCodes, true)) {
                throw new RuntimeException('Unrecognized BIR result code requires certification review.');
            }
        }
        if (($data['successCountQuantity'] ?? null) !== $accepted || ($data['failCountQuantity'] ?? null) !== count($expected) - $accepted) {
            throw new RuntimeException('BIR result counts do not reconcile.');
        }

        return ['state' => $accepted === count($expected) ? 'accepted' : ($accepted === 0 ? 'rejected' : 'partial'), 'documents' => $rows];
    }
}
