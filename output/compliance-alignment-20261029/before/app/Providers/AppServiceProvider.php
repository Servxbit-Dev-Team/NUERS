<?php

namespace App\Providers;

use App\Services\Infrastructure\ControllerGateway;
use App\Services\Infrastructure\MtlsController;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ControllerGateway::class, MtlsController::class);
        $this->app->singleton(\App\Services\DashboardRevision::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\DB::listen(fn ($event) => app(\App\Services\DashboardRevision::class)->observe($event));
    }
}
