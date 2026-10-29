<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Invoice identity and printed registration evidence; never confers BIR approval. */
class InvoiceDocumentRules
{
    public const NUMBER_MAX = 50; // EIS CompInvoiceId, distinct from the 24-character EisUniqueId.

    public function numberError(string $number): ?string
    {
        return $number === '' || preg_match('/^\s|\s$/u', $number) || mb_strlen($number) > self::NUMBER_MAX || preg_match('/[\x00-\x1F\x7F]/u', $number)
            ? 'Document number must contain 1 to 50 characters without surrounding whitespace or control characters. Preserve the registered seller number; do not substitute an EIS unique ID.' : null;
    }

    public function assertNumber(string $number): void
    {
        if ($error = $this->numberError($number)) throw ValidationException::withMessages(['receipt_number' => $error]);
    }

    public function registrationRules(string $prefix = 'document_registration'): array
    {
        $fields = ['system_type', 'pti_number', 'ptu_number', 'ac_number', 'authority_issued_at', 'series_start', 'series_end', 'machine_identification_number', 'machine_serial_number', 'software_license_number', 'non_vat_basis'];
        $rules = [$prefix => ['nullable', 'array:'.implode(',', $fields)]];
        foreach ($fields as $field) $rules[$prefix.'.'.$field] = ['nullable', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'];
        $rules[$prefix.'.system_type'] = ['required_with:'.$prefix, 'in:CAS,POS,ELECTRONIC'];
        $rules[$prefix.'.authority_issued_at'] = ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'];
        $rules[$prefix.'.non_vat_basis'] = ['nullable', 'in:percentage_tax,exempt'];
        foreach (['series_start','series_end'] as $field) $rules[$prefix.'.'.$field] = ['nullable', 'string', 'max:50', 'not_regex:/[\x00-\x1F\x7F]/u'];
        return $rules;
    }

    public function registration(array $value, bool $issuing = false): array
    {
        $value = Validator::make(['document_registration' => $value], $this->registrationRules())->validate()['document_registration'];
        if ($issuing) {
            abort_unless(!empty($value['authority_issued_at']), 409, 'Record the authentic authority issue date for this document series before issuance.');
            if (($value['system_type'] ?? '') === 'CAS') abort_unless(!empty($value['ptu_number']) || !empty($value['ac_number']), 409, 'CAS issuance requires the authentic system PTU or AC. PTI is a separate authority.');
            if (($value['system_type'] ?? '') === 'ELECTRONIC') abort_unless(!empty($value['pti_number']), 409, 'Electronic issuance requires the authentic PTI number.');
            if (($value['system_type'] ?? '') === 'POS') abort_unless(!empty($value['ptu_number']) && !empty($value['machine_identification_number']) && (!empty($value['machine_serial_number']) || !empty($value['software_license_number'])), 409, 'POS issuance requires the recorded MIN, machine/software identifier and final PTU.');
        }
        return $value;
    }

    public function registeredTin(?string $tin, ?string $branch): ?string
    {
        $identity = app(SellerIdentity::class)->parse($tin, $branch);
        if (!$identity['valid'] || !preg_match('/^\d{5}$/D', (string)$identity['branch_code'])) return null;
        return substr($identity['tin'],0,3).'-'.substr($identity['tin'],3,3).'-'.substr($identity['tin'],6,3).'-'.$identity['branch_code'];
    }

    public function assertBuyer(?string $type, ?string $name, ?string $tin, ?string $address): void
    {
        if ($type !== 'business') return;
        $digits = preg_replace('/\D/', '', (string)$tin);
        abort_unless(trim((string)$name) !== '' && trim((string)$address) !== '' && preg_match('/^[0-9 -]+$/D', (string)$tin) && in_array(strlen($digits), [9,12,14], true) && !preg_match('/^0+$/', $digits), 422, 'A business buyer requires its registered name, TIN and registered address. B2C buyer address/TIN remain optional.');
    }
}
