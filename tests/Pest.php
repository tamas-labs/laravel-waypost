<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Waypost\Http\Middleware\Authenticate;
use Waypost\Models\Device;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Schema\Paths;
use Waypost\Tests\InstallTestCase;
use Waypost\Tests\TestCase;
use Waypost\Validation\SchemaValidator;

use function Pest\Laravel\artisan;
use function Pest\Laravel\call;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->extend(InstallTestCase::class)->in('Install');

/** The package's tables without prefix. A new migration's table goes here too. */
const WAYPOST_TABLES = [
    'users', 'personal_access_tokens', 'sites', 'site_user', 'site_user_detachments', 'devices', 'checkpoints', 'routes',
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

/*
 * expect($response)->toMatchSchema('api/auth-login.response'): the JSON body of a TestResponse is valid against a
 * schema of waypost/schema. The failure lists opis/json-schema's errors by JSON pointer.
 */
expect()->extend('toMatchSchema', function (string $name) {
    $response = $this->value;
    assert($response instanceof TestResponse);
    $content = (string) $response->getContent();
    $error = app(SchemaValidator::class)->validate(json_decode($content, flags: JSON_THROW_ON_ERROR), $name);
    $errors = $error === null ? [] : (new ErrorFormatter())->format($error);

    expect($errors)->toBe([], "The response does not match {$name}: " . json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n{$content}");

    return $this;
});

/** The bearer token of a supervisor, as POST /auth/login issues it. */
function supervisorToken(User $user): string
{
    return $user->createToken('test', [Authenticate::SUPERVISOR])->plainTextToken;
}

/** The bearer token of a site phone, as POST /devices/enroll issues it. */
function deviceToken(Device $device): string
{
    return $device->createToken('test', [Authenticate::DEVICE])->plainTextToken;
}

/** A supervisor assigned to the sites. */
function supervisorOf(Site ...$sites): User
{
    $user = User::factory()->supervisor()->create();
    $user->sites()->attach(array_map(fn (Site $site): string => $site->id, $sites));

    return $user;
}

/**
 * A request to the API (the path under the prefix) by a supervisor with their own token, or by the site phone acting
 * for them (X-Acting-User). A write request gets a fresh Idempotency-Key; an empty body is sent as {}.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function callApi(string $method, string $path, User $supervisor, array $body = [], ?Device $phone = null): TestResponse
{
    $headers = ['Authorization' => 'Bearer ' . ($phone === null ? supervisorToken($supervisor) : deviceToken($phone))];
    if ($phone !== null) {
        $headers[Authenticate::ACTING_USER_HEADER] = $supervisor->id;
    }

    return requestApi($method, $path, $headers, $body);
}

/**
 * A request to the API (the path under the prefix) with the headers (call() ignores withHeaders()). A write request
 * gets a fresh Idempotency-Key unless the headers have one; an empty body is sent as {}.
 *
 * @param  array<string, string>  $headers
 * @param  array<string, mixed>  $body
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function requestApi(string $method, string $path, array $headers, array $body = []): TestResponse
{
    if ($method !== 'GET' && !isset($headers['Idempotency-Key'])) {
        $headers['Idempotency-Key'] = (string) Str::uuid();
    }
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    foreach ($headers as $name => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $content = $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR);

    return call($method, "api/waypost/v1/{$path}", server: $server, content: $content);
}

/**
 * GET /sync/pull with the token of a supervisor or a site phone.
 *
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function pullAs(User|Device $caller, ?string $cursor = null): TestResponse
{
    $token = $caller instanceof User ? supervisorToken($caller) : deviceToken($caller);
    $query = $cursor === null ? '' : '?cursor=' . rawurlencode($cursor);

    return requestApi('GET', "sync/pull{$query}", ['Authorization' => "Bearer {$token}"]);
}
