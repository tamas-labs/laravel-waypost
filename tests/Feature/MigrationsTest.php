<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

/** The package's tables without prefix. */
const WAYPOST_TABLES = [
    'users', 'personal_access_tokens', 'sites', 'site_user', 'devices', 'checkpoints', 'routes',
    'route_versions', 'route_version_checkpoints', 'schedules', 'report_subscriptions',
    'chain_entries', 'shifts', 'rounds', 'scans', 'incidents', 'media', 'reports', 'idempotency_keys',
];

/**
 * Tables of the test database (not of the server: the workbench database is there too).
 *
 * @return list<string>
 */
function tablesWithPrefix(string $prefix): array
{
    $tables = Schema::getTableListing(Schema::getCurrentSchemaListing(), schemaQualified: false);

    return array_values(array_filter($tables, fn (string $name): bool => str_starts_with($name, $prefix)));
}

it('creates every table with the prefix next to the host application\'s own tables', function (): void {
    expect(Artisan::call('migrate:fresh'))->toBe(0);

    expect(tablesWithPrefix('waypost_'))->toEqualCanonicalizing(array_map(Table::name(...), WAYPOST_TABLES))
        // The host's tables (here: Testbench's skeleton) stay as they are.
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'role'))->toBeFalse();

    // Every time is a datetime with milliseconds. Not a MySQL timestamp: that converts with the session's
    // time zone and would shift the UTC values that fall into a DST gap of that zone.
    $timeColumns = [];
    foreach (WAYPOST_TABLES as $table) {
        foreach (Schema::getColumns(Table::name($table)) as $column) {
            if (in_array($column['type_name'], ['datetime', 'timestamp'], true)) {
                $timeColumns["{$table}.{$column['name']}"] = $column['type'];
            }
        }
    }

    expect($timeColumns)->not->toBeEmpty()
        ->and(array_diff($timeColumns, ['datetime(3)', 'timestamp(3) without time zone']))->toBe([]);
});

it('rolls back completely', function (): void {
    $migrations = glob(dirname(__DIR__, 2) . '/database/migrations/*.php') ?: [];

    expect(Artisan::call('migrate:rollback', ['--step' => count($migrations)]))->toBe(0);

    expect(tablesWithPrefix('waypost_'))->toBe([])
        ->and(Schema::hasTable('users'))->toBeTrue();

    expect(Artisan::call('migrate'))->toBe(0);

    expect(tablesWithPrefix('waypost_'))->toHaveCount(count(WAYPOST_TABLES));
});

it('uses the configured prefix', function (): void {
    config(['waypost.table_prefix' => 'wp_']);

    expect(Artisan::call('migrate:fresh'))->toBe(0);

    expect(tablesWithPrefix('wp_'))->toHaveCount(count(WAYPOST_TABLES))
        ->and(tablesWithPrefix('waypost_'))->toBe([]);

    // The other test files expect the default prefix (RefreshDatabase migrates only once per run).
    config(['waypost.table_prefix' => 'waypost_']);
    expect(Artisan::call('migrate:fresh'))->toBe(0);
});
