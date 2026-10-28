<?php

declare(strict_types=1);

namespace Waypost\Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use Waypost\WaypostServiceProvider;

/**
 * An application that has only installed the package, as after composer require: no workbench, so the migrator
 * knows only the application's database/migrations. Its config and database directories are fresh temporary
 * ones, and the test database is wiped before and after every test.
 */
abstract class InstallTestCase extends Orchestra
{
    protected string $appDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appDirectory = sys_get_temp_dir() . '/waypost-install-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->appDirectory . '/config');
        File::ensureDirectoryExists($this->appDirectory . '/database/migrations');
        $this->app?->useConfigPath($this->appDirectory . '/config');
        $this->app?->useDatabasePath($this->appDirectory . '/database');

        Artisan::call('db:wipe');
    }

    protected function tearDown(): void
    {
        Artisan::call('db:wipe');
        // The other suites migrate the test database again (RefreshDatabase migrates once per run).
        RefreshDatabaseState::$migrated = false;
        File::deleteDirectory($this->appDirectory);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [WaypostServiceProvider::class];
    }
}
