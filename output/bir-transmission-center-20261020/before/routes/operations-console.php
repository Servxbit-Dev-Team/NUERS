<?php

use App\Services\LocalRecoveryBackup;
use App\Services\OperationsHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('operations:check', function () {
    $result = app(OperationsHealth::class)->collect();
    $this->line(json_encode($result, JSON_THROW_ON_ERROR));

    return $result['acceptance_ready'] ? 0 : 1;
})->purpose('Observe NUERS storage, cache, scheduler-driven workers and pending work; no external traffic');
Schedule::command('operations:check')->everyMinute()->withoutOverlapping(5);

Artisan::command('operations:integrity', function () {
    $result = app(OperationsHealth::class)->integrity();
    $this->line(json_encode($result, JSON_THROW_ON_ERROR));

    return $result['valid'] ? 0 : 1;
})->purpose('Read-only audit-chain and wallet reconciliation; discrepancies are never auto-corrected');
Schedule::command('operations:integrity')->hourly()->withoutOverlapping(60);

Artisan::command('operations:backup', function () {
    try {
        $this->line(json_encode(app(LocalRecoveryBackup::class)->create(), JSON_THROW_ON_ERROR));

        return 0;
    } catch (Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }
})->purpose('Create an encrypted local recovery archive with a separately provisioned key; opt-in only');
Schedule::command('operations:backup')->dailyAt('02:30')->when(fn () => config('operations.backup.enabled'))->withoutOverlapping(120);

Artisan::command('operations:verify-backup {archive_id}', function () {
    try {
        $result = app(LocalRecoveryBackup::class)->verify($this->argument('archive_id'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return 0;
    } catch (Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }
})->purpose('Authenticate a local archive and verify recovered files in a fresh temporary directory; never import into production');
