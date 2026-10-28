<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Waypost\Models\PersonalAccessToken;
use Waypost\Models\User;
use Waypost\Support\Time;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function login(array $body): TestResponse
{
    return postJson('api/waypost/v1/auth/login', $body);
}

function supervisor(): User
{
    return User::factory()->supervisor()->create(['name' => 'Nagy Anna', 'email' => 'nagy.anna@example.com']);
}

it('issues a non-expiring supervisor token', function (): void {
    travelTo(Time::fromIso('2026-10-27T20:00:00.000Z'));
    $user = supervisor();

    $response = login(['email' => 'nagy.anna@example.com', 'password' => 'password', 'device_name' => 'Pixel 8 (Nagy Anna)'])->assertOk();

    expect($response)->toMatchSchema('api/auth-login.response');
    expect($response->json())->toMatchArray([
        'server_time' => '2026-10-27T20:00:00.000Z',
        'user' => ['id' => $user->id, 'name' => 'Nagy Anna', 'email' => 'nagy.anna@example.com', 'role' => 'supervisor'],
    ]);
    $token = PersonalAccessToken::findToken($response->json('token'));
    expect($token?->tokenable?->is($user))->toBeTrue();
    expect($token?->abilities)->toBe(['supervisor']);
    expect($token?->name)->toBe('Pixel 8 (Nagy Anna)');
    expect($token?->expires_at)->toBeNull();
});

it('issues a token that works for the API', function (): void {
    supervisor();
    $token = login(['email' => 'nagy.anna@example.com', 'password' => 'password', 'device_name' => 'Pixel 8'])->json('token');

    postJson('api/waypost/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])->assertOk();
});

it('finds the address whatever its case', function (): void {
    supervisor();

    login(['email' => 'Nagy.Anna@Example.COM', 'password' => 'password', 'device_name' => 'Pixel 8'])->assertOk();
});

it('refuses wrong credentials and guards the same way', function (array $body): void {
    supervisor();
    User::factory()->create(['email' => 'kiss.peter@example.com', 'password' => 'password']);

    $response = login([...$body, 'device_name' => 'Pixel 8'])->assertUnauthorized();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error'))->toBe(['code' => 'unauthenticated', 'message' => 'Hibás e-mail-cím vagy jelszó.']);
    expect(PersonalAccessToken::query()->count())->toBe(0);
})->with([
    'wrong password' => [['email' => 'nagy.anna@example.com', 'password' => 'wrong']],
    'unknown address' => [['email' => 'senki@example.com', 'password' => 'password']],
    'guard with a password' => [['email' => 'kiss.peter@example.com', 'password' => 'password']],
]);

it('refuses an inactive supervisor with the right password', function (): void {
    supervisor()->update(['active' => false]);

    $response = login(['email' => 'nagy.anna@example.com', 'password' => 'password', 'device_name' => 'Pixel 8'])->assertUnauthorized();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.message'))->toBe('Ez a fiók inaktív, nem lehet vele belépni.');
});

it('refuses a deleted supervisor', function (): void {
    supervisor()->delete();

    login(['email' => 'nagy.anna@example.com', 'password' => 'password', 'device_name' => 'Pixel 8'])->assertUnauthorized();
});

it('validates the body against the schema', function (): void {
    $response = login(['email' => 'nem-email', 'password' => '', 'pin' => '123456'])->assertUnprocessable();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error'))->toBe([
        'code' => 'validation_failed',
        'message' => 'A megadott adatok hibásak.',
        'details' => [
            'device_name' => ['Kötelező mező.'],
            'email' => ['Érvénytelen e-mail-cím.'],
            'password' => ['Nem lehet üres.'],
            'pin' => ['Ismeretlen mező.'],
        ],
    ]);
});

it('limits the attempts per address', function (): void {
    config(['waypost.rate_limits.login' => 3]);
    supervisor();
    $wrong = ['email' => 'nagy.anna@example.com', 'password' => 'wrong', 'device_name' => 'Pixel 8'];

    foreach (range(1, 3) as $attempt) {
        login($wrong)->assertUnauthorized();
    }
    $response = login([...$wrong, 'password' => 'password'])->assertTooManyRequests()->assertHeader('Retry-After');

    expect($response->json('error.code'))->toBe('rate_limited');
    login(['email' => 'masik@example.com', 'password' => 'wrong', 'device_name' => 'Pixel 8'])->assertUnauthorized();
});

it('deletes the token of the request on logout', function (): void {
    travelTo(Time::fromIso('2026-10-27T20:00:00.000Z'));
    $user = supervisor();
    $token = supervisorToken($user);
    $other = supervisorToken($user);

    $response = postJson('api/waypost/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])->assertOk();

    expect($response)->toMatchSchema('api/auth-logout.response');
    expect($response->json())->toBe(['server_time' => '2026-10-27T20:00:00.000Z']);
    postJson('api/waypost/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    expect($user->tokens()->count())->toBe(1);
    postJson('api/waypost/v1/auth/logout', [], ['Authorization' => "Bearer {$other}"])->assertOk();
});

it('lets only a supervisor log out', function (): void {
    postJson('api/waypost/v1/auth/logout')->assertUnauthorized();
    $device = Waypost\Models\Device::factory()->create();

    postJson('api/waypost/v1/auth/logout', [], ['Authorization' => 'Bearer ' . deviceToken($device)])->assertForbidden();
    expect($device->tokens()->count())->toBe(1);
});

it('names the routes', function (): void {
    expect(route('waypost.auth.login', absolute: false))->toBe('/api/waypost/v1/auth/login');
    expect(route('waypost.auth.logout', absolute: false))->toBe('/api/waypost/v1/auth/logout');
    getJson('api/waypost/v1/auth/login')->assertStatus(405)->assertJsonPath('error.code', 'bad_request');
});
