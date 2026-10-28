<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Waypost\Enums\OrderMode;
use Waypost\Enums\Role;
use Waypost\Enums\ScheduleMode;
use Waypost\Enums\TagType;
use Waypost\Models\Checkpoint;
use Waypost\Models\Device;
use Waypost\Models\Route;
use Waypost\Models\Schedule;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Pin\PinHash;
use Waypost\Pin\PinHasher;
use Waypost\Support\Time;
use Waypost\Sync\Pull;
use Workbench\Database\Seeders\DatabaseSeeder;

use function Pest\Laravel\seed;
use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

function pinMatches(User $user, string $pin): bool
{
    $hash = PinHash::of($user);

    return $hash !== null && app(PinHasher::class)->check($pin, $hash);
}

it('seeds the demo site', function (): void {
    seed(DatabaseSeeder::class);

    $site = Site::query()->sole();
    $supervisor = User::query()->where('email', DatabaseSeeder::SUPERVISOR_EMAIL)->sole();

    expect($site->users()->count())->toBe(3)
        ->and($supervisor->role)->toBe(Role::Supervisor)
        ->and(password_verify(DatabaseSeeder::SUPERVISOR_PASSWORD, (string) $supervisor->password))->toBeTrue()
        ->and(pinMatches($supervisor, DatabaseSeeder::SUPERVISOR_PIN))->toBeTrue();

    foreach (DatabaseSeeder::GUARDS as $name => $pin) {
        $guard = User::query()->where('name', $name)->sole();

        expect($guard->role)->toBe(Role::Guard)
            ->and(pinMatches($guard, $pin))->toBeTrue();
    }

    expect(Checkpoint::query()->where('tag_type', TagType::Nfc)->count())->toBe(4)
        ->and(Checkpoint::query()->where('tag_type', TagType::Qr)->whereNotNull('qr_payload')->count())->toBe(2);

    $routes = Route::query()->with('currentVersion.checkpoints')->orderBy('name')->get();

    expect($routes->pluck('order_mode')->all())->toBe([OrderMode::Free, OrderMode::Fixed])
        ->and($routes->map(fn (Route $route) => $route->currentVersion?->checkpoints->pluck('name')->all())->all())
        ->toBe([
            ['Hátsó kapu', 'Parkoló', 'Kazánház', 'Tetőfeljáró'],
            ['Főbejárat', 'Raktár', 'Hátsó kapu', 'Parkoló'],
        ]);

    $schedule = Schedule::query()->sole();

    expect($schedule->route_id)->toBe($routes[1]?->id)
        ->and($schedule->mode)->toBe(ScheduleMode::Interval)
        ->and($schedule->interval_every_min)->toBe(5);
});

it('gives the whole demo state to a phone enrolled to the demo site', function (): void {
    seed(DatabaseSeeder::class);
    $phone = Device::factory()->for(Site::query()->sole())->create();
    travelTo(Time::now()->addMilliseconds(Pull::SETTLE_MS + 1));

    $response = pullAs($phone)->assertOk();

    expect($response)->toMatchSchema('api/sync-pull.response');
    expect($response->json('has_more'))->toBeFalse();
    expect(array_map(count(...), $response->json('changes')))->toBe([
        'sites' => 1, 'users' => 3, 'devices' => 1, 'checkpoints' => 6, 'routes' => 2, 'route_versions' => 2,
        'route_version_checkpoints' => 8, 'schedules' => 1, 'report_subscriptions' => 0,
    ]);
    expect($response->json('changes.users.*.pin_hash'))->each->not->toBeNull();
});
