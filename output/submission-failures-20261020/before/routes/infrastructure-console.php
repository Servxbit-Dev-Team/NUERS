<?php

use App\Services\Infrastructure\ActionWorkflow;
use App\Services\Infrastructure\Audit;
use App\Services\Infrastructure\ControllerGateway;
use App\Services\Infrastructure\Telemetry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('infrastructure:sync', function () {
    if (! app(ControllerGateway::class)->ready()) {
        $this->error('Independent management controller is not configured.');

        return 1;
    }
    try {
        $n = app(Telemetry::class)->ingest(app(ControllerGateway::class)->snapshot());
        $this->info("Verified $n fresh node observations.");

        return 0;
    } catch (Throwable $e) {
        DB::table('infra_state')->where('id', 1)->update(['sync_state' => 'unavailable']);
        $this->error('Telemetry unavailable or rejected. Review identity pins, mTLS and controller contract. No node health inferred.');

        return 1;
    }
})->purpose('Read pinned controller telemetry; never execute server commands');

Artisan::command('infrastructure:work {--limit=5}', function () {
    if (! config('infrastructure.execution_enabled') || ! app(ControllerGateway::class)->ready()) {
        $this->error('Infrastructure execution is disabled or controller unavailable.');

        return 1;
    }
    $flow = app(ActionWorkflow::class);
    $flow->reconcile();
    for ($i = 0; $i < max(1, min(25, (int) $this->option('limit'))); $i++) {
        $id = $flow->dispatchOne();
        if (! $id) {
            break;
        } $this->info('Processed action '.$id);
    }

    return 0;
})->purpose('Dispatch approved typed jobs and reconcile ambiguous outcomes; opt-in independent worker only');

Artisan::command('infrastructure:audit-verify', function () {
    $valid = app(Audit::class)->verify();
    $this->line($valid ? 'Infrastructure audit chain verified.' : 'Infrastructure audit chain mismatch.');

    return $valid ? 0 : 1;
})->purpose('Verify the local infrastructure audit chain; off-host preservation is separately required');
