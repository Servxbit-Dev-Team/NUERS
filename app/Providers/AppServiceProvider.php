<?php

namespace App\Providers;

use App\Support\SchemaMap;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Schema lookups are memoised per process; a migration run can add tables and columns.
        Event::listen(MigrationsEnded::class, fn () => SchemaMap::flush());
    }
}
