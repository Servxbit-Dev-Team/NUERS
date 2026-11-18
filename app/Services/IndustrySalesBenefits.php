<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Entry controls based on the approved business activity; each sale still needs eligibility evidence. */
class IndustrySalesBenefits
{
    public function definition(): array
    {
        static $definition;

        return $definition ??= json_decode(file_get_contents(resource_path('data/industry-sales-benefits.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function catalog(object $merchant): array
    {
        $definition = $this->definition();
        $sector = trim((string) ($merchant->sector ?? ''));
        $code = preg_match('/^(?:psic:)?(\d{5})$/', $sector, $match) ? $match[1] : ($definition['legacy_aliases'][$sector] ?? null);
        static $industries;
        $industries ??= app(RegistrationIndustry::class)->catalog()['industries'];
        $activity = $industries['psic:'.$code] ?? null;
        $scopes = [];
        $notes = [];
        $matched = [];
        if ($activity) {
            foreach ($definition['rules'] as $rule) {
                if (!in_array($code, $rule['codes'], true)) {
                    continue;
                }
                $matched[] = $rule['id'];
                $notes[] = $rule['note'];
                foreach ($rule['grants'] as $category => $goods) {
                    $scopes[$category] = array_values(array_unique([
                        ...($scopes[$category] ?? []),
                        ...array_intersect($goods, StatutorySales::SCOPES[$category]),
                    ]));
                }
            }
        }
        $scopes = array_filter($scopes);
        $status = $scopes ? 'covered_activity' : (!$activity || in_array($code, $definition['review_codes'], true) ? 'classification_review' : 'regular_only');

        return [
            'policy_version' => $definition['version'],
            'policy_status' => $status,
            'industry_code' => $activity ? $code : null,
            'industry_name' => $activity['name'] ?? ($sector ?: 'Unclassified activity'),
            'categories' => ['regular', ...array_values(array_intersect(StatutorySales::CATEGORIES, array_keys($scopes)))],
            'scopes' => (object) $scopes,
            'matched_rules' => $matched,
            'conditions' => array_values(array_unique($notes)),
            'notice' => match ($status) {
                'covered_activity' => 'Only categories mapped to the registered activity are shown. Verify the buyer and covered items before applying a benefit.',
                'classification_review' => 'Regular Sale is available. This classification needs review of the actual business activity before statutory categories can be enabled. Ask the administrator to confirm the correct PSIC classification.',
                default => 'Regular Sale is available. No 20% or 10% statutory category is mapped to this activity. If the business also sells covered goods or services, ask the administrator to review its classification.',
            },
        ];
    }

    public function assertAllowed(object $merchant, ?array $benefit): void
    {
        if (!$benefit) {
            return;
        }
        $catalog = $this->catalog($merchant);
        $scopes = (array) $catalog['scopes'];
        if (!in_array($benefit['scope'] ?? '', $scopes[$benefit['category'] ?? ''] ?? [], true)) {
            throw ValidationException::withMessages(['sales_benefit.scope' => 'This statutory sale category or covered item is not enabled for the merchant’s registered business activity. Have the administrator review the classification before applying the benefit.']);
        }
    }
}
