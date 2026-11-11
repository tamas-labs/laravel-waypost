<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

it('hashes the password also when there is none to check, so that the time tells nothing', function (string $email): void {
    supervisor();
    User::factory()->supervisor()->create(['email' => 'jelszo.nelkul@example.com', 'password' => null]);
    User::factory()->create(['email' => 'kiss.peter@example.com', 'password' => 'password']);
    $hasher = Mockery::spy(Hasher::class);
    Hash::swap($hasher);

    login(['email' => $email, 'password' => 'password', 'device_name' => 'Pixel 8'])->assertUnauthorized();

    $hasher->shouldHaveReceived('make')->once()->with('password');
    $hasher->shouldNotHaveReceived('check');
})->with([
    'unknown address' => ['senki@example.com'],
    'guard with a password' => ['kiss.peter@example.com'],
    'supervisor without a password' => ['jelszo.nelkul@example.com'],
]);

it('takes as long without an account as with a wrong password, with the real hasher (FR-015)', function (): void {
    config(['hashing.bcrypt.rounds' => 10, 'waypost.rate_limits.login' => 100]);
    Hash::forgetDrivers(); // the hasher keeps the rounds it was made with
    supervisor();
    $median = function (array $body): float {
        $times = [];
        for ($i = 0; $i < 5; $i++) {
            $start = hrtime(true);
            login([...$body, 'device_name' => 'Pixel 8'])->assertUnauthorized();
            $times[] = hrtime(true) - $start;
        }
        sort($times);

        return (float) $times[2];
    };
    $median(['email' => 'senki@example.com', 'password' => 'password']); // the first request warms up the framework

    $wrongPassword = $median(['email' => 'nagy.anna@example.com', 'password' => 'wrong']);
    $unknownAddress = $median(['email' => 'senki@example.com', 'password' => 'password']);

    // A bcrypt of 10 rounds is tens of milliseconds; without the hash the unknown address would answer in a few.
    expect($unknownAddress / $wrongPassword)->toBeGreaterThan(0.5)->toBeLessThan(2.0);
});

it('hashes the password again at the login if it was hashed with older settings', function (): void {
    config(['hashing.bcrypt.rounds' => 4]);
    Hash::forgetDrivers();
    $old = supervisor()->password;
    config(['hashing.bcrypt.rounds' => 5]);
    Hash::forgetDrivers();

    login(['email' => 'nagy.anna@example.com', 'password' => 'password', 'device_name' => 'Pixel 8'])->assertOk();

    $stored = User::query()->where('email', 'nagy.anna@example.com')->sole()->password;
    expect($stored)->not->toBe($old)
        ->and(Hash::needsRehash((string) $stored))->toBeFalse()
        ->and(Hash::check('password', (string) $stored))->toBeTrue();
    login(['email' => 'nagy.anna@example.com', 'password' => 'password', 'device_name' => 'Pixel 8'])->assertOk();
    expect(User::query()->where('email', 'nagy.anna@example.com')->sole()->password)->toBe($stored);
});

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

it('limits the attempts per IP address, whatever the address', function (): void {
    config(['waypost.rate_limits.login_ip' => 3]);
    supervisor();

    foreach (['a', 'b', 'c'] as $name) {
        login(['email' => "{$name}@example.com", 'password' => 'wrong', 'device_name' => 'Pixel 8'])->assertUnauthorized();
    }
    $response = login(['email' => 'nagy.anna@example.com', 'password' => 'password', 'device_name' => 'Pixel 8'])
        ->assertTooManyRequests()->assertHeader('Retry-After');

    expect($response->json('error.code'))->toBe('rate_limited');
    postJson('api/waypost/v1/auth/login', ['email' => 'nagy.anna@example.com', 'password' => 'password', 'device_name' => 'Pixel 8'], ['REMOTE_ADDR' => '10.0.0.2'])
        ->assertOk();
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
