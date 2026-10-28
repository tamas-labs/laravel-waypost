<?php

declare(strict_types=1);

namespace Waypost;

use Illuminate\Support\ServiceProvider;

final class WaypostServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/waypost.php', 'waypost');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/waypost.php' => config_path('waypost.php'),
            ], 'waypost-config');

            // Published (waypost:install) rather than loaded, so the host application owns its migrations.
            $this->publishesMigrations([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'waypost-migrations');
        }
    }
}
