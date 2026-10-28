<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Waypost\Enums\Role;
use Waypost\Models\ChainEntry;
use Waypost\Models\Checkpoint;
use Waypost\Models\Device;
use Waypost\Models\IdempotencyKey;
use Waypost\Models\Incident;
use Waypost\Models\Media;
use Waypost\Models\PersonalAccessToken;
use Waypost\Models\Report;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Round;
use Waypost\Models\Route;
use Waypost\Models\RouteVersion;
use Waypost\Models\RouteVersionCheckpoint;
use Waypost\Models\Scan;
use Waypost\Models\Schedule;
use Waypost\Models\Shift;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Table;
use Waypost\Support\Uuid;

pest()->use(RefreshDatabase::class);

/** @return array<string, mixed> */
function rawRow(string $table, string $id): array
{
    return (array) DB::table(Table::name($table))->where('id', $id)->first();
}

/** @param  Factory<EloquentModel>  $factory */
it('creates every model with its factory', function (Factory $factory): void {
    $model = $factory->createOne();

    expect($model->exists)->toBeTrue()
        ->and($model->getTable())->toStartWith('waypost_')
        ->and(DB::table($model->getTable())->count())->toBeGreaterThan(0);

    if (! $model instanceof RouteVersionCheckpoint) {
        expect($model->getKey())->toMatch(Uuid::PATTERN);
    }
})->with([
    'User' => fn () => User::factory(),
    'Site' => fn () => Site::factory(),
    'Device' => fn () => Device::factory(),
    'Checkpoint' => fn () => Checkpoint::factory(),
    'Route' => fn () => Route::factory(),
    'RouteVersion' => fn () => RouteVersion::factory(),
    'RouteVersionCheckpoint' => fn () => RouteVersionCheckpoint::factory(),
    'Schedule' => fn () => Schedule::factory(),
    'ReportSubscription' => fn () => ReportSubscription::factory(),
    'ChainEntry' => fn () => ChainEntry::factory(),
    'Shift' => fn () => Shift::factory(),
    'Round' => fn () => Round::factory(),
    'Scan' => fn () => Scan::factory(),
    'Incident' => fn () => Incident::factory(),
    'Media' => fn () => Media::factory(),
    'Report' => fn () => Report::factory(),
    'IdempotencyKey' => fn () => IdempotencyKey::factory(),
]);

it('creates supervisors with a password and users with a PBKDF2 PIN', function (): void {
    $supervisor = User::factory()->supervisor()->create();
    $guard = User::factory()->withPin('123456')->create();

    expect($supervisor->role)->toBe(Role::Supervisor)
        ->and(password_verify('password', (string) $supervisor->password))->toBeTrue()
        ->and($supervisor->pin_hash)->toBeNull()
        ->and($guard->role)->toBe(Role::Guard)
        ->and($guard->pin_iterations)->toBe(100_000)
        ->and(strlen((string) base64_decode((string) $guard->pin_salt, true)))->toBe(16)
        ->and(base64_encode(hash_pbkdf2('sha256', '123456', (string) base64_decode((string) $guard->pin_salt), 100_000, 32, true)))
        ->toBe($guard->pin_hash);
});

it('makes QR payloads that match the schema and name the checkpoint', function (): void {
    $checkpoint = Checkpoint::factory()->qr()->create();
    /** @var array{properties: array{qr_payload: array{anyOf: list<array{pattern: string}>}}} $definition */
    $definition = schema('entities/checkpoint');
    $pattern = '/' . $definition['properties']['qr_payload']['anyOf'][0]['pattern'] . '/D';

    expect($checkpoint->qr_payload)->toMatch($pattern)
        ->toStartWith("waypost:cp:{$checkpoint->id}:")
        ->and($checkpoint->tag_uid)->toBeNull();
});

it('encrypts the device secret at rest and hides it', function (): void {
    $device = Device::factory()->create();

    $stored = rawRow('devices', $device->id)['secret'];

    expect($stored)->not->toBe($device->secret)
        ->and(Crypt::decryptString((string) $stored))->toBe($device->secret)
        ->and(strlen((string) base64_decode(Device::query()->findOrFail($device->id)->secret, true)))->toBe(32)
        ->and($device->toArray())->not->toHaveKey('secret');
});

it('keeps schedule times in the HH:MM form', function (): void {
    $schedule = Schedule::factory()->interval('20:00', '08:00', 60)->create();

    expect(rawRow('schedules', $schedule->id)['interval_from'])->toStartWith('20:00')
        ->and(Schedule::query()->findOrFail($schedule->id)->interval_from)->toBe('20:00')
        ->and(Schedule::query()->findOrFail($schedule->id)->interval_to)->toBe('08:00');
});

it('rejects a malformed schedule time', function (string $time): void {
    Schedule::factory()->interval($time)->make();
})->with(['8:00', '24:00', '20:00:00', '20:60'])->throws(InvalidArgumentException::class);

it('orders the checkpoints of a route version by position', function (): void {
    $version = RouteVersion::factory()->create();
    $first = Checkpoint::factory()->create();
    $second = Checkpoint::factory()->create();
    RouteVersionCheckpoint::factory()->for($version)->for($second)->create(['position' => 2, 'walk_time_from_prev_s' => 45]);
    RouteVersionCheckpoint::factory()->for($version)->for($first)->create(['position' => 1]);

    $checkpoints = $version->checkpoints()->get();

    expect($checkpoints->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($version->points()->pluck('position')->all())->toBe([1, 2])
        ->and($version->points()->pluck('walk_time_from_prev_s')->all())->toBe([null, 45]);
});

it('numbers route versions and chain entries consecutively', function (): void {
    $route = Route::factory()->create();
    $device = Device::factory()->create();

    // One create() at a time: the next number is read from the database.
    $versions = array_map(fn () => RouteVersion::factory()->for($route)->create()->version, range(1, 3));
    $seqs = array_map(fn () => ChainEntry::factory()->for($device)->create()->seq, range(1, 3));

    expect($versions)->toBe([1, 2, 3])
        ->and($seqs)->toBe([1, 2, 3]);
});

it('assigns users to sites', function (): void {
    $site = Site::factory()->create();
    $guard = User::factory()->create();

    $site->users()->attach($guard);

    expect($guard->sites()->pluck('id')->all())->toBe([$site->id])
        ->and(DB::table(Table::name('site_user'))->count())->toBe(1);
});

/** Creates the same row on every call: a supervisor e-mail, a site's tag, a QR payload or a subscription. */
function duplicateCreator(string $case): Closure
{
    return match ($case) {
        'users.email' => fn () => User::factory()->supervisor()->create(['email' => 'a@example.com']),
        'checkpoints.tag_uid' => (function () {
            $site = Site::factory()->create();

            return fn () => Checkpoint::factory()->for($site)->create(['tag_uid' => '04A1B2C3D4E580']);
        })(),
        'checkpoints.qr_payload' => fn () => Checkpoint::factory()->create([
            'qr_payload' => 'waypost:cp:0192e7a0-0000-7000-8000-000000000000:AbCdEfGhIjKlMnOp',
        ]),
        'report_subscriptions' => (function () {
            $user = User::factory()->supervisor()->create();
            $site = Site::factory()->create();

            return fn () => ReportSubscription::factory()->for($user)->for($site)->create();
        })(),
        default => throw new InvalidArgumentException("Unknown case {$case}."),
    };
}

it('keeps e-mail addresses, tags and subscriptions unique among rows that are not deleted', function (string $case): void {
    $create = duplicateCreator($case);
    $first = $create();

    // In a savepoint: on PostgreSQL a failed statement aborts the whole transaction.
    expect(fn () => DB::transaction(fn () => $create()))->toThrow(UniqueConstraintViolationException::class);

    $first->delete();

    expect($create()->exists)->toBeTrue()
        ->and($first->toArray())->not->toHaveKey('not_deleted');
})->with(['users.email', 'checkpoints.tag_uid', 'checkpoints.qr_payload', 'report_subscriptions']);

it('allows the same tag on different sites', function (): void {
    Checkpoint::factory()->create(['tag_uid' => '04A1B2C3D4E580']);

    expect(Checkpoint::factory()->create(['tag_uid' => '04A1B2C3D4E580'])->exists)->toBeTrue();
});

it('keeps the tokens of users and devices in the package\'s own table', function (string $class): void {
    /** @var User|Device $owner */
    $owner = $class === User::class ? User::factory()->supervisor()->create() : Device::factory()->create();

    $plainText = $owner->createToken('test', ['ability'])->plainTextToken;
    $token = PersonalAccessToken::findToken($plainText);

    expect($token)->not->toBeNull()
        ->and($token?->getTable())->toBe(Table::name('personal_access_tokens'))
        ->and($token?->id)->toMatch(Uuid::PATTERN)
        ->and($token?->tokenable?->is($owner))->toBeTrue()
        ->and($token?->can('ability'))->toBeTrue()
        ->and($owner->tokens()->count())->toBe(1)
        // Sanctum's global token model, used by the host application, is untouched.
        ->and(Sanctum::$personalAccessTokenModel)->toBe(\Laravel\Sanctum\PersonalAccessToken::class);
})->with([User::class, Device::class]);
