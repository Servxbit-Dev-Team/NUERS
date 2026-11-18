<?php

namespace App\Services;

class RegisteredTin
{
    /** Equivalent recorded forms of the same nine-digit TIN and branch. */
    public static function variants(string $value): array
    {
        $digits = preg_replace('/\D/', '', $value);
        if (! in_array(strlen($digits), [9, 12, 14], true)) {
            return [$digits];
        }

        $base = substr($digits, 0, 9);
        $branch = str_pad(substr($digits, 9), 5, '0', STR_PAD_LEFT);
        $variants = [$base.$branch];
        if (str_starts_with($branch, '00')) {
            $variants[] = $base.substr($branch, 2);
        }
        if ($branch === '00000') {
            $variants[] = $base;
        }

        return array_values(array_unique($variants));
    }
}
