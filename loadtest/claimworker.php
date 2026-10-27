<?php

/*
 * One claiming worker for claimrace.php: leases deliveries until none are due, records "id token" for each lease, and then
 * completes the delivery at once (as a real worker would after a successful callback), so leased rows do not pile up.
 *
 *   php loadtest/claimworker.php <out-file> [legacy]
 *
 * With "legacy" it uses the claim query as it was before 2026-10-27 (one query over all states, locking every due row it sorts)
 * so the two can be compared. Same safety guard as seed.php.
 */

use App\Services\MerchantWebhooks;
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

$out = $argv[1] ?? null;
if (! $out) {
    fwrite(STDERR, "usage: claimworker.php <out-file> [legacy]\n");
    exit(1);
}
$legacy = ($argv[2] ?? '') === 'legacy';
$service = app(MerchantWebhooks::class);
$claim = $legacy
    ? fn () => DB::transaction(function () {
        $d = DB::table('merchant_webhook_deliveries')->whereIn('state', ['pending', 'retry', 'sending'])->where('available_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))->orderBy('available_at')->orderBy('id')->lockForUpdate()->first();
        if (! $d) {
            return null;
        }
        $d->lease_token = (string) Str::uuid();
        DB::table('merchant_webhook_deliveries')->where('id', $d->id)->update(['lease_token' => $d->lease_token, 'lease_until' => now()->addMinutes(3), 'state' => 'sending', 'updated_at' => now()]);

        return $d;
    }, 3)
    : fn () => $service->claim();

$handle = fopen($out, 'ab');
$n = 0;
$started = microtime(true);
while (($d = $claim()) !== null) {
    fwrite($handle, $d->id.' '.$d->lease_token."\n");
    // The callback "succeeded": finish the delivery, releasing the lease, as the real worker does.
    DB::table('merchant_webhook_deliveries')->where('id', $d->id)->where('lease_token', $d->lease_token)->update(['state' => 'delivered', 'lease_token' => null, 'lease_until' => null, 'delivered_at' => now(), 'updated_at' => now()]);
    $n++;
}
fclose($handle);
// If the loop ended while due, unleased deliveries still exist, a worker gave up early (that would be a defect).
$stillDue = DB::table('merchant_webhook_deliveries')->whereIn('state', ['pending', 'retry'])->where('available_at', '<=', now())->whereNull('lease_until')->count();
fwrite(STDOUT, json_encode(['claimed' => $n, 'seconds' => round(microtime(true) - $started, 2), 'due_left' => $stillDue])."\n");
