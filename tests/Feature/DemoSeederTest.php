<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Waypost\Enums\OrderMode;
use Waypost\Enums\Role;
use Waypost\Enums\ScheduleMode;
use Waypost\Enums\TagType;
use Waypost\Models\Checkpoint;
use Waypost\Models\Route;
use Waypost\Models\Schedule;
use Waypost\Models\Site;
use Waypost\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

use function Pest\Laravel\seed;

pest()->use(RefreshDatabase::class);

function pinMatches(User $user, string $pin): bool
{
    $hash = hash_pbkdf2('sha256', $pin, (string) base64_decode((string) $user->pin_salt), max(1, (int) $user->pin_iterations), 32, true);

    return hash_equals((string) $user->pin_hash, base64_encode($hash));
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
