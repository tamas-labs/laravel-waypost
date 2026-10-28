<?php

declare(strict_types=1);

namespace Waypost\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;
use Waypost\Support\Time;
use Waypost\WaypostServiceProvider;

/**
 * Installs the package into the host application: publishes config/waypost.php and the migrations, then runs the
 * migrations on request. Safe to run again, e.g. after an update: it publishes only the migrations the
 * application neither has as a file nor has run (matched by name without the date), and keeps an existing config
 * file.
 */
#[AsCommand(name: 'waypost:install')]
final class InstallCommand extends Command
{
    protected $signature = 'waypost:install
        {--migrate : A migrációk futtatása kérdés nélkül}
        {--force : A meglévő config/waypost.php felülírása}';

    protected $description = 'A Waypost telepítése: a konfiguráció és a migrációk publikálása, igény szerint a migrációk futtatása';

    private const string DATE_PREFIX = '/^\d{4}_\d{2}_\d{2}_\d{6}_/';

    public function handle(Filesystem $files): int
    {
        $this->publishConfig($files);
        $this->publishMigrations($files);

        $migrate = (bool) $this->option('migrate')
            || ($this->input->isInteractive() && $this->confirm('Lefuttassam most a migrációkat?', true));

        if ($migrate) {
            if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        } else {
            $this->components->info('A migrációk később a php artisan migrate paranccsal futtathatók.');
        }

        $this->components->info('Következő lépés: az első supervisor létrehozása a php artisan waypost:create-supervisor paranccsal.');

        return self::SUCCESS;
    }

    private function publishConfig(Filesystem $files): void
    {
        $target = $this->laravel->configPath('waypost.php');

        if ($files->exists($target) && !$this->option('force')) {
            $this->components->warn('A config/waypost.php már létezik, nem írtam felül (--force felülírja).');

            return;
        }

        $files->ensureDirectoryExists(dirname($target));
        $files->copy(WaypostServiceProvider::CONFIG_FILE, $target);
        $this->components->info('A konfiguráció publikálva: config/waypost.php.');
    }

    /**
     * File names of the migrations that have run, also those whose file is gone (e.g. squashed into a schema dump).
     *
     * @return list<string>
     */
    private function ranMigrations(): array
    {
        $repository = $this->laravel->make('migrator')->getRepository();
        if (!$repository->repositoryExists()) {
            return [];
        }

        /** @var list<string> $ran */
        $ran = $repository->getRan();

        return array_map(fn (string $migration): string => "{$migration}.php", $ran);
    }

    /**
     * Copies the package's migrations the application does not have yet, renamed to the current time (one second
     * apart, in the package's order), so they sort after the application's existing migrations.
     */
    private function publishMigrations(Filesystem $files): void
    {
        $target = $this->laravel->databasePath('migrations');
        $files->ensureDirectoryExists($target);

        $known = [...array_map(basename(...), $files->glob($target . '/*.php')), ...$this->ranMigrations()];
        $existing = array_map(fn (string $name): string => (string) preg_replace(self::DATE_PREFIX, '', $name), $known);

        $sources = $files->glob(WaypostServiceProvider::MIGRATIONS_DIRECTORY . '/*.php');
        sort($sources, SORT_STRING);

        // After the latest migration of the application, even if that is dated later than now.
        $time = Time::now()->startOfSecond();
        $latest = max(['', ...array_map(fn (string $name): string => substr($name, 0, 17), preg_grep(self::DATE_PREFIX, $known) ?: [])]);
        if ($latest >= $time->format('Y_m_d_His')) {
            $time = CarbonImmutable::createFromFormat('Y_m_d_His', $latest, 'UTC') ?: $time;
        }
        $published = 0;
        foreach ($sources as $source) {
            $name = (string) preg_replace(self::DATE_PREFIX, '', basename($source));
            if (in_array($name, $existing, true)) {
                continue;
            }

            $time = $time->addSecond();
            $files->copy($source, $target . '/' . $time->format('Y_m_d_His') . '_' . $name);
            $published++;
        }

        if ($published === 0) {
            $this->components->info('A migrációk már publikálva vannak.');
        } else {
            $this->components->info("{$published} migráció publikálva a database/migrations könyvtárba.");
        }
    }
}
