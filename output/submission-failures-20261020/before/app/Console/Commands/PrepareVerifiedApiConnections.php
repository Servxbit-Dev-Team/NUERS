<?php

namespace App\Console\Commands;

use App\Services\MerchantApiOnboarding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareVerifiedApiConnections extends Command
{
    protected $signature = 'integrations:prepare-verified-connections';
    protected $description = 'Idempotently prepare owned-system connection metadata for existing active business accounts. No keys or transactions are generated.';

    public function handle(): int
    {
        $ready = $blocked = 0;
        DB::table('merchants')->where('status', 'active')->whereNotNull('merchant_account_id')->orderBy('id')->chunk(100, function ($merchants) use (&$ready, &$blocked) {
            foreach ($merchants as $m) {
                $ok = DB::transaction(function () use ($m) {
                    $merchant = DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
                    if (! $merchant || $merchant->status !== 'active') return false;
                    $profile = app(MerchantApiOnboarding::class)->prepare($merchant, 'system:verified-api-setup');
                    return $profile && $profile->status === 'active' && app(MerchantApiOnboarding::class)->matches($profile, $merchant);
                }, 3);
                $ok ? $ready++ : $blocked++;
            }
        });
        $this->line(json_encode(['ready' => $ready, 'needs_registration_or_connection_review' => $blocked, 'keys_generated' => 0, 'financial_events_created' => 0]));
        return self::SUCCESS;
    }
}
