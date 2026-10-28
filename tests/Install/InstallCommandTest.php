<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;
use Waypost\WaypostServiceProvider;

/**
 * Names of the published migrations, sorted.
 *
 * @return list<string>
 */
function publishedMigrations(): array
{
    $names = array_map(basename(...), File::glob(database_path('migrations/*.php')));
    sort($names, SORT_STRING);

    return $names;
}

/** @return list<string> */
function packageMigrations(): array
{
    $names = array_map(basename(...), File::glob(WaypostServiceProvider::MIGRATIONS_DIRECTORY . '/*.php'));
    sort($names, SORT_STRING);

    return $names;
}

/** The name without the date, e.g. create_waypost_users_table.php. */
function withoutDate(string $migration): string
{
    return (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $migration);
}

it('publishes the config and the migrations and runs them', function (): void {
    artisanCommand('waypost:install', ['--migrate' => true])
        ->expectsOutputToContain('A konfiguráció publikálva: config/waypost.php.')
        ->expectsOutputToContain(count(packageMigrations()) . ' migráció publikálva a database/migrations könyvtárba.')
        ->expectsOutputToContain('a php artisan waypost:create-supervisor paranccsal.')
        ->assertSuccessful();

    expect(File::get(config_path('waypost.php')))->toBe(File::get(WaypostServiceProvider::CONFIG_FILE))
        // In the package's order, dated now, so they run after the application's own migrations.
        ->and(array_map(withoutDate(...), publishedMigrations()))->toBe(array_map(withoutDate(...), packageMigrations()))
        ->and(publishedMigrations()[0])->toStartWith(now()->utc()->format('Y_m_d_'))
        ->and(tablesWithPrefix('waypost_'))->toEqualCanonicalizing(array_map(Table::name(...), WAYPOST_TABLES));
});

it('asks before running the migrations', function (string $answer, bool $migrated): void {
    artisanCommand('waypost:install')
        ->expectsConfirmation('Lefuttassam most a migrációkat?', $answer)
        ->assertSuccessful();

    expect(publishedMigrations())->toHaveCount(count(packageMigrations()))
        ->and(Schema::hasTable(Table::name('users')))->toBe($migrated);
})->with([
    'yes' => ['yes', true],
    'no' => ['no', false],
]);

it('does not migrate without --migrate when it cannot ask', function (): void {
    artisanCommand('waypost:install', ['--no-interaction' => true])
        ->expectsOutputToContain('A migrációk később a php artisan migrate paranccsal futtathatók.')
        ->assertSuccessful();

    expect(publishedMigrations())->toHaveCount(count(packageMigrations()))
        ->and(Schema::hasTable(Table::name('users')))->toBeFalse();
});

it('can run again: publishes only the missing migrations and keeps the config', function (): void {
    expect(Artisan::call('waypost:install', ['--no-interaction' => true]))->toBe(0);
    $first = publishedMigrations();
    File::put(config_path('waypost.php'), "<?php\n\nreturn [];\n");

    artisanCommand('waypost:install', ['--no-interaction' => true])
        ->expectsOutputToContain('A config/waypost.php már létezik')
        ->expectsOutputToContain('A migrációk már publikálva vannak.')
        ->assertSuccessful();

    expect(publishedMigrations())->toBe($first)
        ->and(File::get(config_path('waypost.php')))->toBe("<?php\n\nreturn [];\n");

    // A newer version of the package brings a migration the application does not have yet.
    File::delete(database_path('migrations/' . $first[13]));
    $first = array_slice($first, 0, 13);

    artisanCommand('waypost:install', ['--migrate' => true, '--force' => true])
        ->expectsOutputToContain('1 migráció publikálva')
        ->expectsOutputToContain('A konfiguráció publikálva')
        ->assertSuccessful();

    // Dated after the others, even within the same seconds.
    expect(array_slice(publishedMigrations(), 0, 13))->toBe($first)
        ->and(publishedMigrations()[13])->toBeGreaterThan($first[12])
        ->and(withoutDate(publishedMigrations()[13]))->toBe(withoutDate(packageMigrations()[13]))
        ->and(File::get(config_path('waypost.php')))->toBe(File::get(WaypostServiceProvider::CONFIG_FILE))
        ->and(tablesWithPrefix('waypost_'))->toHaveCount(count(WAYPOST_TABLES));
});

it('does not publish a migration again that has run, even if its file is gone', function (): void {
    expect(Artisan::call('waypost:install', ['--migrate' => true]))->toBe(0);
    // As after php artisan schema:dump --prune.
    File::cleanDirectory(database_path('migrations'));

    artisanCommand('waypost:install', ['--migrate' => true])
        ->expectsOutputToContain('A migrációk már publikálva vannak.')
        ->assertSuccessful();

    expect(publishedMigrations())->toBe([]);
});
