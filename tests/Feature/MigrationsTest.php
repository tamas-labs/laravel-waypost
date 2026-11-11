<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

/**
 * SHA-256 of every migration a release has shipped: a host that ran one never runs it again, so a change would never
 * reach it (WP-BE-037). Every schema change is a new migration; at a release, its migrations are added here.
 */
const RELEASED_MIGRATIONS = [
    '2026_10_28_000001_create_waypost_users_table.php' => '92eacdba081333caa6f35c9bf676a83cd5b54e4e7dcbdf918bb7f1a665ad1ddb',
    '2026_10_28_000002_create_waypost_personal_access_tokens_table.php' => '6dc306757b5e8baafcb9d4f0f765564f743c9a7b8a12ffd00fffc32e74088a04',
    '2026_10_28_000003_create_waypost_sites_table.php' => 'bfabd5532ce551d0760b91e02b67d882647a1e2238bc39e1bec7021d3ca097dd',
    '2026_10_28_000004_create_waypost_devices_table.php' => 'dd39d08701d50696da5f785648a9c2258c4e3786003f983526fc24895f276088',
    '2026_10_28_000005_create_waypost_checkpoints_table.php' => '6ee0f09bcce9f9e7ac95d1acf0dd132db2e051f596790850064c36b66ea35545',
    '2026_10_28_000006_create_waypost_routes_table.php' => '8f5f629b796dc25a0da6bcfdfd361e12768fb5ad53c76378fb745fd64b9cc506',
    '2026_10_28_000007_create_waypost_schedules_table.php' => 'ab053784b7255762d6a349b556238b6e153e20623f23c26822163c81cd8534c5',
    '2026_10_28_000008_create_waypost_report_subscriptions_table.php' => 'e4d93fb4709f5233be73e67a52dd41056dcaf1652f14250a3280455762e6050d',
    '2026_10_28_000009_create_waypost_chain_entries_table.php' => 'fe0d35e84d20feaca2da87b3f3899774a80ab56bb49d97339b9f799b15d68a26',
    '2026_10_28_000010_create_waypost_shifts_table.php' => 'b187f813bbd9fd1dc9c9b8325d7de8fd81abf9f2c6095221450e29d82060509c',
    '2026_10_28_000011_create_waypost_scans_table.php' => '87d294d3d40063ae67e07cc50d66b0ccbd21d72090bd0177ce480bd838ad2b5a',
    '2026_10_28_000012_create_waypost_media_table.php' => 'bb291970304e91338d5eb8e0f289f414d3f546882b4e147f135916b0bb60aff4',
    '2026_10_28_000013_create_waypost_reports_table.php' => 'dfc443bad09591e20c2045b94171aab8b7d587cd7c94831d7e1ac07e834506d1',
    '2026_10_28_000014_create_waypost_idempotency_keys_table.php' => 'a6d1012e90090507a339daea4315a1bca41fd86a90e5d2388bf81faedc6de677',
    '2026_10_28_000015_create_waypost_site_user_detachments_table.php' => '09f1907da05fbd37f066fbc626e047bebfaf9b87f36ff6b5c0fbcc98fc1f6138',
    '2026_11_02_000016_create_waypost_device_diagnostics_table.php' => '1750d5d4fdb49ecf11e15e5b4f4ac3af4555459b9a401e0978901ac60b9ea86e',
    '2026_11_04_000017_create_waypost_no_data_alerts_table.php' => 'b8e9032f0e2f3d73b235ff698b5f6165d0c49c26bc7f24f8290772e377931006',
    '2026_11_05_000018_add_waypost_foreign_key_indexes.php' => '63bdbec0010da57bbb7355b64f9d2a7aa917a136c677999e1174c52e96f75fb6',
    '2026_11_05_000019_add_waypost_reports_delivered_to.php' => '8e581b00fc6996672c75db0fb4314c1649c40db3877cc1436157eac887e4e9ac',
    '2026_11_05_000020_use_waypost_morph_aliases.php' => 'cc8b1f23959b5451467b1d9570c8363e7fbe9a135de68dd3786689c5100b9e13',
    '2026_11_10_000021_add_waypost_devices_pending_cursor.php' => '41cea171d4400e1ad2993bf2c1b28ad789deb90ce62074a4a54d707992b44b1f',
];

it('never changes a released migration', function (): void {
    $directory = dirname(__DIR__, 2) . '/database/migrations/';
    foreach (RELEASED_MIGRATIONS as $file => $sha256) {
        expect(is_file($directory . $file))->toBeTrue("{$file} is gone")
            ->and(hash_file('sha256', $directory . $file))->toBe($sha256, "{$file} changed: put the change in a new migration");
    }
});

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

it('indexes every foreign key: PostgreSQL and SQLite do not do it by themselves, as InnoDB does', function (): void {
    expect(Artisan::call('migrate:fresh'))->toBe(0);

    $unindexed = [];
    foreach (WAYPOST_TABLES as $table) {
        $leading = array_map(fn (array $index): array => $index['columns'], Schema::getIndexes(Table::name($table)));
        foreach (Schema::getForeignKeys(Table::name($table)) as $foreignKey) {
            $columns = $foreignKey['columns'];
            $covered = array_filter($leading, fn (array $indexed): bool => array_slice($indexed, 0, count($columns)) === $columns);
            if ($covered === []) {
                $unindexed[] = "{$table}." . implode(',', $columns);
            }
        }
    }

    expect($unindexed)->toBe([]);
});
