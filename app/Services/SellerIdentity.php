<?php

namespace App\Services;

class SellerIdentity
{
    /** Preserve the original snapshot; extract only values explicitly encoded in that snapshot. */
    public function parse(?string $tin, ?string $branch): array
    {
        $digits = preg_replace('/\D/', '', (string) $tin);
        $embedded = match (strlen($digits)) {
            12 => '00'.substr($digits, 9, 3),14 => substr($digits, 9, 5),default => null
        };
        $base = in_array(strlen($digits), [12, 14], true) ? substr($digits, 0, 9) : $digits;

        return ['tin' => $base, 'branch_code' => $branch ?: $embedded, 'branch_source' => $branch ? 'issued_snapshot' : ($embedded ? 'embedded_in_issued_tin' : 'unknown'),
            'valid' => strlen($base) === 9 && (! $branch || preg_match('/^\d{5}$/', $branch)) && ! ($embedded && $branch && $embedded !== $branch),
            'original_tin' => $tin];
    }
}
