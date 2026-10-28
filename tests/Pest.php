<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Waypost\Schema\Paths;
use Waypost\Tests\InstallTestCase;
use Waypost\Tests\TestCase;

use function Pest\Laravel\artisan;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->extend(InstallTestCase::class)->in('Install');

/** The package's tables without prefix. A new migration's table goes here too. */
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

/**
 * A schema of waypost/schema, decoded, e.g. schema('common/uuid').
 *
 * @return array<string, mixed>
 */
function schema(string $name): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(Paths::schema($name)), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The "pattern" of a waypost/schema schema as a PCRE, e.g. schemaPattern('common/uuid'). The D modifier keeps
 * "$" from matching before a trailing newline, as in JSON Schema (and opis/json-schema).
 */
function schemaPattern(string $name): string
{
    $pattern = schema($name)['pattern'];
    assert(is_string($pattern));

    return '/' . $pattern . '/D';
}

/**
 * Pest\Laravel\artisan() as the pending command it is in tests (the int return is only for disabled output mocking).
 *
 * @param  array<string, mixed>  $parameters
 */
function artisanCommand(string $command, array $parameters = []): PendingCommand
{
    $pending = artisan($command, $parameters);
    assert($pending instanceof PendingCommand);

    return $pending;
}
