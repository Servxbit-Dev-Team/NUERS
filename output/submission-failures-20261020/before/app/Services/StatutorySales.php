<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Eligibility is checked by the seller against original evidence, never inferred from a customer label. */
class StatutorySales
{
    public const CATEGORIES = ['regular', 'senior_citizen', 'pwd', 'national_athlete_coach', 'solo_parent'];
    public const SCOPES = [
        'senior_citizen' => ['restaurant', 'hotel', 'recreation', 'admission', 'medicine', 'medical_dental', 'domestic_transport', 'funeral'],
        'pwd' => ['restaurant', 'hotel', 'recreation', 'admission', 'medicine', 'medical_dental', 'domestic_transport', 'funeral'],
        'national_athlete_coach' => ['restaurant', 'hotel', 'recreation', 'admission', 'medicine', 'sports_equipment', 'domestic_transport'],
        'solo_parent' => ['baby_milk', 'baby_food', 'micronutrient_supplements', 'diapers', 'medicine', 'vaccines', 'medical_supplements'],
    ];

    public function validate(?array $benefit, string $date): ?array
    {
        if ($benefit === null || $benefit === []) return null;
        $v = Validator::make(['sales_benefit' => $benefit], [
            'sales_benefit' => 'required|array:category,scope,beneficiary_name,id_number,evidence_reference,eligibility_confirmed,child_birth_date,id_valid_until,income_below_250000,booklet_reference',
            'sales_benefit.category' => ['required', Rule::in(array_slice(self::CATEGORIES, 1))],
            'sales_benefit.scope' => 'required|string|max:60',
            'sales_benefit.beneficiary_name' => 'required|string|min:2|max:200|not_regex:/[\x00-\x1F\x7F]/u',
            'sales_benefit.id_number' => 'required|string|min:2|max:100|not_regex:/[\x00-\x1F\x7F]/u',
            'sales_benefit.evidence_reference' => 'required|string|min:5|max:500',
            'sales_benefit.eligibility_confirmed' => 'required|accepted',
            'sales_benefit.child_birth_date' => 'nullable|date_format:Y-m-d',
            'sales_benefit.id_valid_until' => 'nullable|date_format:Y-m-d',
            'sales_benefit.income_below_250000' => 'nullable|boolean',
            'sales_benefit.booklet_reference' => 'nullable|string|max:200',
        ])->validate()['sales_benefit'];
        $day = CarbonImmutable::parse($date)->setTimezone('Asia/Manila')->startOfDay();
        if (!in_array($v['scope'], self::SCOPES[$v['category']], true)) $this->fail('scope', 'Choose a covered goods/services category. Ordinary retail goods do not automatically qualify for the 20% benefit.');
        if ($v['category'] === 'solo_parent') {
            if (empty($v['income_below_250000']) || empty($v['child_birth_date']) || empty($v['id_valid_until']) || empty(trim($v['booklet_reference'] ?? ''))) $this->fail('category', 'Solo-parent sales require a valid SPIC, purchase booklet, child birth date and confirmed annual income below PHP 250,000.');
            $birth = CarbonImmutable::parse($v['child_birth_date'], 'Asia/Manila');
            if ($birth->gt($day) || $birth->addYears(7)->lte($day)) $this->fail('child_birth_date', 'The child must be six years old or younger on the transaction date.');
        }
        if (!empty($v['id_valid_until']) && $v['id_valid_until'] < $day->toDateString()) $this->fail('id_valid_until', 'Eligibility identification was expired on the transaction date.');
        return $v;
    }

    public function storage(?array $benefit, array $totals, bool $centavos = false): array
    {
        return ['sale_category' => $benefit['category'] ?? 'regular', 'sales_benefit' => $benefit ? json_encode($benefit, JSON_THROW_ON_ERROR) : null,
            'statutory_discount_amount' => ($totals['statutory_discount_amount'] ?? 0) / ($centavos ? 100 : 1),
            'vat_relief_amount' => ($totals['vat_relief_amount'] ?? 0) / ($centavos ? 100 : 1)];
    }

    private function fail(string $key, string $message): never { throw ValidationException::withMessages(['sales_benefit.'.$key => $message]); }
}
