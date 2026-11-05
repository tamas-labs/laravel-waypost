<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Waypost\Http\Middleware\Authenticate;
use Waypost\Http\Middleware\HandleIdempotencyKey;
use Waypost\Http\Middleware\ValidateSchema;
use Waypost\Models\Device;
use Waypost\Models\Site;
use Waypost\Schema\Paths;
use Waypost\Support\Uuid;

pest()->use(RefreshDatabase::class);

/**
 * Who may call an endpoint (spec: API-szerződés); every other endpoint is configuration: supervisor or acting. No
 * caller: no token, login and the files behind signed URLs.
 */
const NOT_CONFIGURATION = [
    'auth.login' => [],
    'media.file' => [],
    'reports.file' => [],
    'reports.index' => [Authenticate::SUPERVISOR],
    'auth.logout' => [Authenticate::SUPERVISOR],
    'devices.enroll' => [Authenticate::SUPERVISOR],
    'media.upload' => [Authenticate::SUPERVISOR, Authenticate::DEVICE, Authenticate::ACTING],
    'sync.pull' => [Authenticate::SUPERVISOR, Authenticate::DEVICE],
    'sync.push' => [Authenticate::DEVICE],
    'sync.push-final' => [Authenticate::DEVICE],
    'devices.diagnostics' => [Authenticate::DEVICE],
];

/**
 * Every API endpoint: method, path under the prefix (parameters filled with new UUIDs) and the accepted callers.
 *
 * @return array<string, array{string, string, list<string>}>
 */
function endpoints(): array
{
    $prefix = 'api/waypost/v1/';
    $endpoints = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RoutingRoute $route */
        $name = (string) $route->getName();
        if (!str_starts_with($name, 'waypost.')) {
            continue;
        }
        $name = substr($name, strlen('waypost.'));
        $path = (string) preg_replace_callback('/\{\w+\}/', fn (): string => Uuid::v7(), substr($route->uri(), strlen($prefix)));
        $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
        $endpoints[$name] = [$method, $path, NOT_CONFIGURATION[$name] ?? [Authenticate::SUPERVISOR, Authenticate::ACTING]];
    }
    ksort($endpoints);

    return $endpoints;
}

it('has the endpoints of the spec', function (): void {
    expect(array_keys(endpoints()))->toBe([
        'auth.login', 'auth.logout',
        'checkpoints.destroy', 'checkpoints.index', 'checkpoints.show', 'checkpoints.store', 'checkpoints.update',
        'devices.diagnostics', 'devices.enroll',
        'media.file', 'media.upload',
        'report-subscriptions.destroy', 'report-subscriptions.index', 'report-subscriptions.show', 'report-subscriptions.store', 'report-subscriptions.update',
        'reports.file', 'reports.index',
        'routes.destroy', 'routes.index', 'routes.show', 'routes.store', 'routes.update',
        'routes.versions.index', 'routes.versions.show', 'routes.versions.store',
        'schedules.destroy', 'schedules.index', 'schedules.show', 'schedules.store', 'schedules.update',
        'sites.destroy', 'sites.index', 'sites.show', 'sites.store', 'sites.update',
        'sites.users.destroy', 'sites.users.index', 'sites.users.store',
        'sync.pull', 'sync.push', 'sync.push-final',
        'users.destroy', 'users.index', 'users.pin', 'users.show', 'users.store', 'users.update',
    ]);
});

it('matches the endpoint table of the contract', function (): void {
    /** @var array{endpoints: list<array{name: string, method: string, path: string, auth: list<string>, idempotency_key: bool, request: array{content_type: string, schema: string}|null}>} $table */
    $table = json_decode((string) file_get_contents(Paths::endpoints()), true, flags: JSON_THROW_ON_ERROR);
    $expected = [];
    foreach ($table['endpoints'] as $endpoint) {
        $expected[$endpoint['name']] = [
            $endpoint['method'],
            $endpoint['path'],
            // No token: login, or a signed URL (checked by the controller).
            array_values(array_diff($endpoint['auth'], ['none', 'signed_url'])),
            $endpoint['idempotency_key'],
            // A multipart body is checked by the controller (MediaController), a JSON body by ValidateSchema.
            ($endpoint['request']['content_type'] ?? null) === 'application/json' ? $endpoint['request']['schema'] : null,
        ];
    }
    ksort($expected);

    $actual = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RoutingRoute $route */
        $name = (string) $route->getName();
        if (!str_starts_with($name, 'waypost.')) {
            continue;
        }
        $middleware = array_map(strval(...), $route->gatherMiddleware());
        $parameters = static fn (string $class): ?string => array_values(array_filter(array_map(
            static fn (string $entry): ?string => str_starts_with($entry, "{$class}:") ? substr($entry, strlen($class) + 1) : null,
            $middleware,
        )))[0] ?? null;
        $callers = $parameters(Authenticate::class);
        $actual[substr($name, strlen('waypost.'))] = [
            array_values(array_diff($route->methods(), ['HEAD']))[0],
            substr($route->uri(), strlen('api/waypost/v1')),
            $callers === null ? [] : explode(',', $callers),
            in_array(HandleIdempotencyKey::class, $middleware, true),
            $parameters(ValidateSchema::class),
        ];
    }
    ksort($actual);

    expect($actual)->toBe($expected);
});

it('needs a token on every endpoint but login and the signed files', function (): void {
    foreach (endpoints() as $name => [$method, $path, $callers]) {
        if ($callers === []) {
            continue;
        }
        $response = requestApi($method, $path, []);

        expect($response->status())->toBe(401, $name);
        expect($response)->toMatchSchema('api/error');
        expect($response->json('error.code'))->toBe('unauthenticated', $name);
    }
});

it('takes each kind of caller only where the spec allows it', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $phone = Device::factory()->for($site)->create();
    // A fresh token for each request: logout deletes its own.
    $headers = [
        Authenticate::SUPERVISOR => fn (): array => ['Authorization' => 'Bearer ' . supervisorToken($supervisor)],
        Authenticate::DEVICE => fn (): array => ['Authorization' => 'Bearer ' . deviceToken($phone)],
        Authenticate::ACTING => fn (): array => ['Authorization' => 'Bearer ' . deviceToken($phone), Authenticate::ACTING_USER_HEADER => $supervisor->id],
    ];

    foreach (endpoints() as $name => [$method, $path, $callers]) {
        if ($callers === []) {
            continue;
        }
        foreach ($headers as $caller => $headersOf) {
            $status = requestApi($method, $path, $headersOf())->status();

            // An accepted caller gets past the authentication: a result, a missing record or an invalid body.
            in_array($caller, $callers, true)
                ? expect($status)->not->toBeIn([401, 403], "{$name} as {$caller}")
                : expect($status)->toBe(403, "{$name} as {$caller}");
        }
    }
});
