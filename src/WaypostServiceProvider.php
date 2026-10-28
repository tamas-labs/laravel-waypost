<?php

declare(strict_types=1);

namespace Waypost;

use Illuminate\Support\ServiceProvider;
use Waypost\Console\CreateSupervisorCommand;
use Waypost\Console\InstallCommand;

final class WaypostServiceProvider extends ServiceProvider
{
    public const string CONFIG_FILE = __DIR__ . '/../config/waypost.php';

    public const string MIGRATIONS_DIRECTORY = __DIR__ . '/../database/migrations';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_FILE, 'waypost');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                CreateSupervisorCommand::class,
            ]);

            $this->publishes([
                self::CONFIG_FILE => config_path('waypost.php'),
            ], 'waypost-config');

            // Published rather than loaded, so the host application owns its migrations. Prefer waypost:install:
            // vendor:publish renames the files to the current date, so a second run would publish them again.
            $this->publishesMigrations([
                self::MIGRATIONS_DIRECTORY => database_path('migrations'),
            ], 'waypost-migrations');
        }
    }
}
