<?php

/*
 * Seeds synthetic merchants, funded wallets, API keys and source profiles for load testing.
 *
 *   php loadtest/seed.php <merchants> <wallet_pesos> <out.json>
 *
 * Refuses to run unless NUERS_LOADTEST=1, the database host is local and the database name
 * contains "load". Never point this at a real database.
 */

use App\Services\MerchantWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfg = config('database.connections.'.config('database.default'));
$host = (string) ($cfg['host'] ?? '');
$name = (string) ($cfg['database'] ?? '');
if (getenv('NUERS_LOADTEST') !== '1' || ! in_array($host, ['127.0.0.1', 'localhost'], true) || ! str_contains($name, 'load')) {
    fwrite(STDERR, "Refusing to seed: need NUERS_LOADTEST=1, a local host and a database name containing 'load' (got host={$host} db={$name}).\n");
    exit(1);
}

$merchants = max(1, (int) ($argv[1] ?? 20));
$pesos = max(1, (int) ($argv[2] ?? 1000000));
$out = $argv[3] ?? __DIR__.'/keys.json';

$admin = DB::table('users')->insertGetId(['name' => 'Load admin', 'email' => 'loadtest-admin@example.invalid', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
DB::table('profiles')->insert(['id' => $admin, 'email' => 'loadtest-admin@example.invalid', 'role' => 'super_admin']);

$keys = [];
for ($i = 1; $i <= $merchants; $i++) {
    $user = DB::table('users')->insertGetId(['name' => "Load merchant $i", 'email' => "loadtest-$i@example.invalid", 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('profiles')->insert(['id' => $user, 'email' => "loadtest-$i@example.invalid", 'role' => 'merchant']);
    $m = (string) Str::uuid();
    DB::table('merchants')->insert(['id' => $m, 'merchant_account_id' => $user, 'business_name' => "Synthetic load merchant $i", 'tin' => '123456789', 'status' => 'active', 'vat_registered' => true]);
    DB::transaction(fn () => app(MerchantWallet::class)->credit($m, $pesos * 100, (string) Str::uuid(), 'Synthetic load funding', (string) $admin));

    $raw = Str::random(64);
    $keyId = (string) Str::uuid();
    DB::table('merchant_api_keys')->insert(['id' => $keyId, 'merchant_id' => $m, 'name' => 'Load key', 'environment' => 'live', 'branch_code' => '00000', 'branch_name' => 'Load', 'branch_type' => 'main', 'key_prefix' => 'lt', 'key_last_four' => substr($raw, -4), 'key_hash' => hash('sha256', $raw), 'scopes' => '["transactions:write","transactions:read"]', 'ip_whitelist' => '[]', 'status' => 'active', 'expires_at' => now()->addYear(), 'rate_limit_per_day' => 100000000]);

    $profile = (string) Str::uuid();
    DB::table('integration_source_profiles')->insert(['id' => $profile, 'merchant_id' => $m, 'branch_code' => '00000', 'source_system' => 'loadtest-pos', 'vat_registered' => true, 'seller_name' => 'Synthetic load issuer', 'seller_tin' => '123456789000', 'seller_address' => 'Synthetic address', 'authority_reference' => 'Synthetic load authority', 'evidence_reference' => 'Synthetic load evidence', 'valid_from' => now()->subYear(), 'valid_until' => now()->addYear(), 'status' => 'active', 'reviewed_by' => 'loadtest', 'reviewed_at' => now(), 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);

    $keys[] = ['merchant_id' => $m, 'key' => $raw, 'profile_id' => $profile];
}

file_put_contents($out, json_encode($keys, JSON_PRETTY_PRINT));
echo "Seeded $merchants merchants, wallets funded with PHP $pesos each. Keys written to $out\n";
