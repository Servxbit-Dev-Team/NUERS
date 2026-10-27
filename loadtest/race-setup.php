<?php

/*
 * Creates one fresh synthetic merchant (wallet, Live API key, source profile) for a race scenario.
 *
 *   php loadtest/race-setup.php <wallet_pesos> <out.json>
 *
 * Safe to run repeatedly. Same safety guard as seed.php: throwaway local database only.
 */

use App\Services\MerchantWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfg = config('database.connections.'.config('database.default'));
if (getenv('NUERS_LOADTEST') !== '1' || ! in_array((string) ($cfg['host'] ?? ''), ['127.0.0.1', 'localhost'], true) || ! str_contains((string) ($cfg['database'] ?? ''), 'load')) {
    fwrite(STDERR, "Refusing to run against a non-load-test database.\n");
    exit(1);
}

$pesos = (int) ($argv[1] ?? 1000);
$out = $argv[2] ?? __DIR__.'/race-merchant.json';
$tag = Str::lower(Str::random(10));

$user = DB::table('users')->insertGetId(['name' => "Race $tag", 'email' => "race-$tag@example.invalid", 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
DB::table('profiles')->insert(['id' => $user, 'email' => "race-$tag@example.invalid", 'role' => 'merchant']);
$m = (string) Str::uuid();
DB::table('merchants')->insert(['id' => $m, 'merchant_account_id' => $user, 'business_name' => "Synthetic race merchant $tag", 'tin' => '123456789', 'status' => 'active', 'vat_registered' => true]);
if ($pesos > 0) {
    DB::transaction(fn () => app(MerchantWallet::class)->credit($m, $pesos * 100, (string) Str::uuid(), 'Synthetic race funding', (string) $user));
}
$raw = Str::random(64);
DB::table('merchant_api_keys')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $m, 'name' => 'Race key', 'environment' => 'live', 'branch_code' => '00000', 'branch_name' => 'Race', 'branch_type' => 'main', 'key_prefix' => 'rc', 'key_last_four' => substr($raw, -4), 'key_hash' => hash('sha256', $raw), 'scopes' => '["transactions:write","transactions:read"]', 'ip_whitelist' => '[]', 'status' => 'active', 'expires_at' => now()->addYear(), 'rate_limit_per_day' => 100000000]);
$profile = (string) Str::uuid();
DB::table('integration_source_profiles')->insert(['id' => $profile, 'merchant_id' => $m, 'branch_code' => '00000', 'source_system' => 'loadtest-pos', 'vat_registered' => true, 'seller_name' => 'Synthetic race issuer', 'seller_tin' => '123456789000', 'seller_address' => 'Synthetic address', 'authority_reference' => 'Synthetic race authority', 'evidence_reference' => 'Synthetic race evidence', 'valid_from' => now()->subYear(), 'valid_until' => now()->addYear(), 'status' => 'active', 'reviewed_by' => 'loadtest', 'reviewed_at' => now(), 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);

file_put_contents($out, json_encode([['merchant_id' => $m, 'key' => $raw, 'profile_id' => $profile]], JSON_PRETTY_PRINT));
echo "Race merchant $m created with wallet PHP $pesos. Written to $out\n";
