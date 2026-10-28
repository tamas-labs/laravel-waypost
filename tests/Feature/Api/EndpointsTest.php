<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Waypost\Http\Middleware\Authenticate;
use Waypost\Models\Device;
use Waypost\Models\Site;
use Waypost\Support\Uuid;

pest()->use(RefreshDatabase::class);

/** Who may call an endpoint (spec: API-szerződés); every other endpoint is configuration: supervisor or acting. */
const NOT_CONFIGURATION = [
    'auth.login' => [],
    'auth.logout' => [Authenticate::SUPERVISOR],
    'devices.enroll' => [Authenticate::SUPERVISOR],
    'sync.pull' => [Authenticate::SUPERVISOR, Authenticate::DEVICE],
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
        'devices.enroll',
        'report-subscriptions.destroy', 'report-subscriptions.index', 'report-subscriptions.show', 'report-subscriptions.store', 'report-subscriptions.update',
        'routes.destroy', 'routes.index', 'routes.show', 'routes.store', 'routes.update',
        'routes.versions.index', 'routes.versions.show', 'routes.versions.store',
        'schedules.destroy', 'schedules.index', 'schedules.show', 'schedules.store', 'schedules.update',
        'sites.destroy', 'sites.index', 'sites.show', 'sites.store', 'sites.update',
        'sites.users.destroy', 'sites.users.index', 'sites.users.store',
        'sync.pull',
        'users.destroy', 'users.index', 'users.pin', 'users.show', 'users.store', 'users.update',
    ]);
});

it('needs a token on every endpoint but login', function (): void {
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
