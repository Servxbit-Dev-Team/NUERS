<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** External POS/ERP intake evidence, distinct from login membership and BIR connectivity. */
class MerchantSystemIntegration
{
    public function present(object $merchant): array
    {
        $keys = DB::table('merchant_api_keys')->where('merchant_id', $merchant->id)->where('environment', 'live');
        $configured = (clone $keys)->exists();
        $active = (clone $keys)->where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '>', now())->get(['id', 'branch_code', 'scopes'])->filter(fn ($k) => preg_match('/^[0-9]{5}$/', (string) $k->branch_code) && in_array('transactions:write', json_decode($k->scopes ?? '[]', true) ?: [], true));
        $accepted = DB::table('integration_inbox as i')->join('merchant_api_keys as k', function ($join) {
            $join->on('k.id', '=', 'i.api_key_id')->on('k.merchant_id', '=', 'i.merchant_id')->on('k.branch_code', '=', 'i.branch_code');
        })->where('i.merchant_id', $merchant->id)->whereIn('k.id', $active->pluck('id'))->where('i.schema_version', 'NUERS-ingestion-1')->whereNotNull('i.receipt_id')->whereNotNull('i.transaction_id')->where('i.source_system', '!=', '');
        $last = (clone $accepted)->max('i.received_at');
        $state = $last ? ($merchant->status === 'active' ? 'integrated' : 'paused') : ($active->isNotEmpty() ? 'configured' : ($configured ? 'inactive' : 'not_integrated'));
        [$label, $caption] = match ($state) {
            'integrated' => ['Integrated', 'External-system transactions received'],
            'configured' => ['Configured', 'Awaiting first accepted API transaction'],
            'paused' => ['Integration Paused', 'Business account requires review'],
            'inactive' => ['Inactive', 'Review API credentials and branch scope'],
            default => ['Not Integrated', 'Connect your POS / ERP to NUERS'],
        };

        return ['status' => $state, 'label' => $label, 'caption' => $caption, 'active_keys' => $active->count(), 'source_systems' => (clone $accepted)->distinct()->count('i.source_system'), 'last_accepted_at' => $last ? CarbonImmutable::parse($last, config('app.timezone'))->toIso8601String() : null, 'note' => 'Based on accepted external API intake with current live credentials. This is not a real-time availability check or BIR connection status.'];
    }
}
