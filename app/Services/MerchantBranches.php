<?php

namespace App\Services;

use App\Http\Controllers\Api\TaxpayerAuthorizationController;
use Carbon\CarbonImmutable as C;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MerchantBranches
{
    public const DOCUMENTS = ['registration' => 'Branch registration / COR reference', 'address' => 'Proof of branch address', 'representative' => 'Authorized branch representative', 'terminal_configuration' => 'POS / API configuration details', 'bir_setup' => 'Applicable BIR setup evidence'];

    public function branch(string $merchant, string $id): object
    {
        $r = DB::table('merchant_branches')->where('merchant_id', $merchant)->where('id', $id)->first();
        abort_unless($r, 404, 'Branch not found.');

        return $r;
    }

    public function terminal(string $merchant, string $id): object
    {
        $r = DB::table('merchant_terminals')->where('merchant_id', $merchant)->where('id', $id)->first();
        abort_unless($r, 404, 'Terminal not found.');

        return $r;
    }

    public function branchRow(object $r): array
    {
        $contact = array_replace(['manager_name' => null, 'email' => null, 'phone' => null], json_decode(Crypt::decryptString($r->contact_encrypted), true, flags: JSON_THROW_ON_ERROR));

        return [...array_diff_key((array) $r, array_flip(['contact_encrypted', 'request_hash', 'request_id', 'address_details'])), ...$contact, 'structured_address' => !empty($r->address_details) ? app(RegistrationAddress::class)->formValue(json_decode($r->address_details, true, flags: JSON_THROW_ON_ERROR), (array) $r) : null];
    }

    public function event(string $merchant, ?string $branch, ?string $terminal, string $action, string $actor, array $details): void
    {
        DB::table('merchant_branch_events')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'branch_id' => $branch, 'terminal_id' => $terminal, 'action' => $action, 'actor_id' => $actor, 'details_encrypted' => Crypt::encryptString(json_encode($details, JSON_THROW_ON_ERROR)), 'created_at' => now()]);
        app(BirCompliance::class)->audit('merchant_registry.'.$action, $branch ?? $terminal, $actor, ['merchant_id' => $merchant, 'branch_id' => $branch, 'terminal_id' => $terminal]);
    }

    public function currentKey(?string $id, string $merchant, string $code): ?object
    {
        if (! $id) {
            return null;
        }
        $key = DB::table('merchant_api_keys')->where('id', $id)->where('merchant_id', $merchant)->where('branch_code', $code)->first();
        abort_unless($key && $key->status === 'active' && $key->expires_at && C::parse($key->expires_at)->isFuture(), 422, 'Select a current API key for this business and exact five-digit branch.');

        return $key;
    }

    public function currentDevice(?string $id, string $merchant, string $code): ?object
    {
        if (! $id) {
            return null;
        }
        $d = DB::table('taxpayer_devices as d')->join('taxpayer_authorizations as a', 'a.id', '=', 'd.authorization_id')->where('d.id', $id)->where('a.merchant_id', $merchant)->where('a.branch_code', $code)->where('a.kind', 'PTT')->select('d.*', 'a.id as permit_id', 'a.environment')->first();
        $p = $d ? DB::table('taxpayer_authorizations')->where('id', $d->permit_id)->first() : null;
        abort_unless($d && $d->status === 'registered' && in_array(app(TaxpayerAuthorizationController::class)->effective($p), ['active', 'expiring_soon']), 422, 'Select a registered device under a current PTT for this taxpayer and branch.');

        return $d;
    }

    public function readiness(object $branch): array
    {
        $i = $branch->bir_branch_code ? app(BirCompliance::class)->integration($branch->merchant_id, $branch->bir_branch_code, 'production') : null;
        $issue = app(BirCompliance::class)->gate($i, false);
        $transmit = app(BirCompliance::class)->gate($i, true);
        $local = [];
        if ($branch->status !== 'active') {
            $local[] = 'Branch operational status is inactive.';
        }if ($branch->review_status !== 'verified') {
            $local[] = 'Branch identity evidence awaits independent administrator review.';
        }

        return ['can_issue' => ! $local && $issue['can_issue'], 'can_transmit' => ! $local && $transmit['can_transmit'], 'blockers' => array_values(array_unique([...$local, ...$transmit['blockers']])), 'note' => 'NUERS branch review is separate from BIR-issued authority and production connectivity.'];
    }

    public function terminals(string $merchant, array $rows): array
    {
        $ids = array_values(array_filter(array_column($rows, 'api_key_id')));
        $keys = DB::table('merchant_api_keys')->where('merchant_id', $merchant)->whereIn('id', $ids)->get()->keyBy('id');
        $last = DB::table('integration_inbox')->where('merchant_id', $merchant)->whereIn('api_key_id', $ids)->select('api_key_id')->selectRaw('MAX(received_at) as seen')->groupBy('api_key_id')->pluck('seen', 'api_key_id');
        $branchIds = array_unique(array_column($rows, 'branch_id'));
        $branches = DB::table('merchant_branches')->where('merchant_id', $merchant)->whereIn('id', $branchIds)->get()->keyBy('id');
        $gates = [];

        return array_map(function ($row) use ($merchant, $keys, $last, $branches, &$gates) {
            $r = (object) $row;
            $b = $branches[$r->branch_id] ?? null;
            $k = $keys[$r->api_key_id] ?? null;
            $seen = $last[$r->api_key_id] ?? null;
            $keyValid = $k && $b && $k->branch_code === $b->bir_branch_code && $k->status === 'active' && $k->expires_at && C::parse($k->expires_at)->isFuture();
            $gates[$r->branch_id] ??= $b ? $this->readiness($b) : ['can_issue' => false];
            $deviceReady = false;
            if ($r->taxpayer_device_id && $b) {
                try {
                    $device = $this->currentDevice($r->taxpayer_device_id, $merchant, (string) $b->bir_branch_code);
                    $deviceReady = $device && $device->environment === 'production';
                } catch (HttpException) {
                }
            }

            return [...array_diff_key($row, array_flip(['request_id', 'request_hash'])), 'branch_name' => $b?->name ?? 'Unavailable branch', 'bir_branch_code' => $b?->bir_branch_code, 'api_key_name' => $k?->name, 'key_environment' => $k?->environment, 'last_activity' => $seen, 'connection_status' => ! $keyValid ? 'not_configured' : (! $seen ? 'not_observed' : (C::parse($seen)->greaterThan(now()->subMinutes(15)) ? 'recent_activity' : 'stale_activity')), 'issuance_ready' => $r->status === 'active' && $keyValid && $k->environment === 'live' && $deviceReady && $gates[$r->branch_id]['can_issue'], 'sync_status' => $seen ? 'intake_recorded' : 'not_observed'];
        }, $rows);
    }
}
