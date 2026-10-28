<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Waypost\Auth\Caller;
use Waypost\Http\Middleware\Authenticate;
use Waypost\Models\Device;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Time;
use Waypost\WaypostServiceProvider;

use function Pest\Laravel\getJson;
use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $describe = fn (Caller $caller): array => [
        'user_id' => $caller->user?->id,
        'device_id' => $caller->device?->id,
        'site_ids' => $caller->siteIds(),
        'request_user' => request()->user()?->getKey(),
    ];
    WaypostServiceProvider::apiRoutes(function () use ($describe): void {
        Route::get('_test/supervisor', $describe)->middleware(Authenticate::using(Authenticate::SUPERVISOR));
        Route::get('_test/device', $describe)->middleware(Authenticate::using(Authenticate::DEVICE));
        Route::get('_test/config', $describe)->middleware(Authenticate::using(Authenticate::SUPERVISOR, Authenticate::ACTING));
        Route::get('_test/pull', $describe)->middleware(Authenticate::using(Authenticate::SUPERVISOR, Authenticate::DEVICE));
    });
});

/**
 * @param  array<string, string>  $headers
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function callAs(string $route, ?string $token, array $headers = []): TestResponse
{
    if ($token !== null) {
        $headers['Authorization'] = "Bearer {$token}";
    }

    return getJson("api/waypost/v1/_test/{$route}", $headers);
}

/** @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response */
function expectError(TestResponse $response, int $status, string $code): void
{
    $response->assertStatus($status);
    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.code'))->toBe($code);
}

it('accepts a supervisor token where a supervisor may call', function (): void {
    $site = Site::factory()->create();
    $other = Site::factory()->create();
    $supervisor = supervisorOf($site, $other);
    $token = supervisorToken($supervisor);

    foreach (['supervisor', 'config', 'pull'] as $route) {
        $response = callAs($route, $token)->assertOk();
        expect($response->json('user_id'))->toBe($supervisor->id);
        expect($response->json('device_id'))->toBeNull();
        expect($response->json('site_ids'))->toEqualCanonicalizing([$site->id, $other->id]);
        expect($response->json('request_user'))->toBe($supervisor->id);
    }
});

it('accepts a device token where the site phone may call', function (): void {
    $device = Device::factory()->create();
    $token = deviceToken($device);

    foreach (['device', 'pull'] as $route) {
        $response = callAs($route, $token)->assertOk();
        expect($response->json('device_id'))->toBe($device->id);
        expect($response->json('user_id'))->toBeNull();
        expect($response->json('site_ids'))->toBe([$device->site_id]);
    }
});

it('refuses a token on a route that does not accept it', function (): void {
    $device = Device::factory()->create();

    expectError(callAs('device', supervisorToken(supervisorOf($device->site()->firstOrFail()))), 403, 'forbidden');
    expectError(callAs('supervisor', deviceToken($device)), 403, 'forbidden');
    $response = callAs('config', deviceToken($device));
    expectError($response, 403, 'forbidden');
    expect($response->json('error.message'))->toContain('X-Acting-User');
});

it('refuses a token without the ability of its owner', function (): void {
    $device = Device::factory()->create();
    $supervisor = supervisorOf($device->site()->firstOrFail());

    expectError(callAs('supervisor', $supervisor->createToken('test', ['device'])->plainTextToken), 403, 'forbidden');
    expectError(callAs('device', $device->createToken('test', ['supervisor'])->plainTextToken), 403, 'forbidden');
});

it('answers unauthenticated without a valid token', function (?string $token): void {
    expectError(callAs('pull', $token), 401, 'unauthenticated');
})->with([
    'no token' => [null],
    'empty' => [''],
    'unknown' => ['0199a1b2-0000-7000-8000-000000000000|' . str_repeat('a', 48)],
    'malformed ID' => ["1' OR '1'='1|secret"],
    'not a UUIDv7' => ['12345|secret'],
    'without ID' => ['just-a-secret'],
]);

it('answers unauthenticated for a wrong secret of an existing token', function (): void {
    $token = supervisorToken(User::factory()->supervisor()->create());
    [$id] = explode('|', $token, 2);

    expectError(callAs('pull', "{$id}|wrong"), 401, 'unauthenticated');
});

it('answers unauthenticated for an expired token', function (): void {
    travelTo(Time::fromIso('2026-10-27T20:00:00.000Z'));
    $user = User::factory()->supervisor()->create();
    $token = $user->createToken('test', [Authenticate::SUPERVISOR], Time::now()->addMinute())->plainTextToken;

    callAs('supervisor', $token)->assertOk();
    travelTo(Time::fromIso('2026-10-27T20:01:00.000Z'));
    expectError(callAs('supervisor', $token), 401, 'unauthenticated');
});

it('answers unauthenticated once the supervisor is inactive, deleted or no longer a supervisor', function (Closure $change): void {
    $user = User::factory()->supervisor()->create();
    $token = supervisorToken($user);
    callAs('supervisor', $token)->assertOk();

    $change($user);

    expectError(callAs('supervisor', $token), 401, 'unauthenticated');
})->with([
    'inactive' => [fn (User $user) => $user->update(['active' => false])],
    'deleted' => [fn (User $user) => $user->delete()],
    'guard' => [fn (User $user) => $user->update(['role' => 'guard'])],
]);

it('answers device_revoked for a revoked device, on every route', function (string $route, array $headers): void {
    $device = Device::factory()->revoked()->create();
    $supervisor = supervisorOf($device->site()->firstOrFail());
    $headers = array_map(fn (string $value): string => str_replace('{supervisor}', $supervisor->id, $value), $headers);

    expectError(callAs($route, deviceToken($device), $headers), 401, 'device_revoked');
})->with([
    'device route' => ['device', []],
    'supervisor route' => ['supervisor', []],
    'acting' => ['config', ['X-Acting-User' => '{supervisor}']],
]);

it('answers unauthenticated for a deleted device', function (): void {
    $device = Device::factory()->create();
    $token = deviceToken($device);
    $device->delete();

    expectError(callAs('device', $token), 401, 'unauthenticated');
});

it('lets the site phone act for a supervisor of its site, only on that site', function (): void {
    $device = Device::factory()->create();
    $other = Site::factory()->create();
    $supervisor = supervisorOf($device->site()->firstOrFail(), $other);

    $response = callAs('config', deviceToken($device), ['X-Acting-User' => $supervisor->id])->assertOk();

    expect($response->json('user_id'))->toBe($supervisor->id);
    expect($response->json('device_id'))->toBe($device->id);
    expect($response->json('site_ids'))->toBe([$device->site_id]);
    // Rate limits and idempotency keys belong to the token's owner.
    expect($response->json('request_user'))->toBe($device->id);
});

it('refuses an X-Acting-User who may not act on the device\'s site', function (Closure $actingUser): void {
    $device = Device::factory()->create();
    /** @var string $id */
    $id = $actingUser($device);

    $response = callAs('config', deviceToken($device), ['X-Acting-User' => $id]);

    expectError($response, 403, 'forbidden');
    expect($response->json('error.message'))->toBe('Az X-Acting-User fejlécben megadott supervisor nem jogosult ezen a telephelyen.');
})->with([
    'supervisor of another site' => [fn (Device $device) => supervisorOf(Site::factory()->create())->id],
    'supervisor of no site' => [fn (Device $device) => User::factory()->supervisor()->create()->id],
    'guard of the site' => [function (Device $device): string {
        $guard = User::factory()->create();
        $guard->sites()->attach($device->site_id);

        return $guard->id;
    }],
    'inactive supervisor' => [function (Device $device): string {
        $supervisor = supervisorOf($device->site()->firstOrFail());
        $supervisor->update(['active' => false]);

        return $supervisor->id;
    }],
    'deleted supervisor' => [function (Device $device): string {
        $supervisor = supervisorOf($device->site()->firstOrFail());
        $supervisor->delete();

        return $supervisor->id;
    }],
    'unknown ID' => [fn (Device $device) => '0199a1b2-0000-7000-8000-000000000000'],
    'not a UUID' => [fn (Device $device) => 'nagy.anna@example.com'],
    'empty' => [fn (Device $device) => ''],
]);

it('refuses X-Acting-User where acting is not allowed or with a supervisor token', function (): void {
    $device = Device::factory()->create();
    $supervisor = supervisorOf($device->site()->firstOrFail());
    $acting = ['X-Acting-User' => $supervisor->id];

    expectError(callAs('pull', deviceToken($device), $acting), 403, 'forbidden');
    expectError(callAs('device', deviceToken($device), $acting), 403, 'forbidden');
    expectError(callAs('config', supervisorToken($supervisor), $acting), 403, 'forbidden');
});

it('authenticates every request on its own token', function (): void {
    $first = User::factory()->supervisor()->create();
    $second = User::factory()->supervisor()->create();

    expect(callAs('supervisor', supervisorToken($first))->json('user_id'))->toBe($first->id);
    expect(callAs('supervisor', supervisorToken($second))->json('user_id'))->toBe($second->id);
    expectError(callAs('supervisor', null), 401, 'unauthenticated');
});

it('does not change the host application\'s default guard', function (): void {
    $default = Auth::getDefaultDriver();

    callAs('supervisor', supervisorToken(User::factory()->supervisor()->create()))->assertOk();

    expect(Auth::getDefaultDriver())->toBe($default);
});
