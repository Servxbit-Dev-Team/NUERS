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
        $this->app->bind(ControllerGateway::class, fn () => config('infrastructure.driver') === 'aws_power' ? new \App\Services\Infrastructure\AwsPowerController() : new MtlsController());
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
