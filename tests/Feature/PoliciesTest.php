<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate as HostGate;
use Waypost\Auth\Caller;
use Waypost\Auth\Gate;
use Waypost\Http\Middleware\Authenticate;
use Waypost\Models\Checkpoint;
use Waypost\Models\Device;
use Waypost\Models\PersonalAccessToken;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Route;
use Waypost\Models\Schedule;
use Waypost\Models\Site;
use Waypost\Models\User;

pest()->use(RefreshDatabase::class);

function supervisorCaller(User $user): Caller
{
    $token = $user->createToken('test', [Authenticate::SUPERVISOR])->accessToken;
    assert($token instanceof PersonalAccessToken);

    return Caller::supervisor($user, $token);
}

function deviceCaller(Device $device, ?User $acting = null): Caller
{
    $token = $device->createToken('test', [Authenticate::DEVICE])->accessToken;
    assert($token instanceof PersonalAccessToken);

    return $acting === null ? Caller::device($device, $token) : Caller::acting($device, $acting, $token);
}

/** @param  mixed[]|mixed  $arguments */
function inspect(Caller $caller, string $ability, mixed $arguments = []): Response
{
    return app(Gate::class)->forUser($caller)->inspect($ability, $arguments);
}

/** "allow", or the status the denial is answered with (403 or 404). */
function outcome(Response $response): string|int
{
    return $response->allowed() ? 'allow' : ($response->status() ?? 403);
}

/**
 * Two sites; the supervisor manages the first only.
 *
 * @return array{Site, Site, User}
 */
function twoSites(): array
{
    $own = Site::factory()->create();
    $foreign = Site::factory()->create();

    return [$own, $foreign, supervisorOf($own)];
}

it('lets a supervisor manage their sites only, and hides the others', function (): void {
    [$own, $foreign, $supervisor] = twoSites();
    $caller = supervisorCaller($supervisor);

    foreach (['view', 'update', 'delete'] as $ability) {
        expect(outcome(inspect($caller, $ability, $own)))->toBe('allow', $ability);
        expect(outcome(inspect($caller, $ability, $foreign)))->toBe(404, $ability);
    }
    expect(outcome(inspect($caller, 'viewAny', Site::class)))->toBe('allow');
    expect(outcome(inspect($caller, 'create', Site::class)))->toBe('allow');
});

it('applies the site rule to what belongs to a site', function (Closure $make, Closure $create): void {
    [$own, $foreign, $supervisor] = twoSites();
    $caller = supervisorCaller($supervisor);
    $mine = $make($own);
    $theirs = $make($foreign);

    foreach (['view', 'update', 'delete'] as $ability) {
        expect(outcome(inspect($caller, $ability, $mine)))->toBe('allow', $ability);
        expect(outcome(inspect($caller, $ability, $theirs)))->toBe(404, $ability);
    }
    expect(outcome(inspect($caller, 'viewAny', $mine::class)))->toBe('allow');
    expect(outcome(inspect($caller, 'create', $create($mine, $own))))->toBe('allow');
    expect(outcome(inspect($caller, 'create', $create($theirs, $foreign))))->toBe(403);
})->with([
    'checkpoint' => [
        fn (Site $site) => Checkpoint::factory()->for($site)->create(),
        fn (Checkpoint $checkpoint, Site $site) => [Checkpoint::class, $site->id],
    ],
    'route' => [
        fn (Site $site) => Route::factory()->for($site)->create(),
        fn (Route $route, Site $site) => [Route::class, $site->id],
    ],
    'schedule' => [
        fn (Site $site) => Schedule::factory()->for(Route::factory()->for($site))->create(),
        fn (Schedule $schedule, Site $site) => [Schedule::class, $schedule->route],
    ],
    'report subscription' => [
        fn (Site $site) => ReportSubscription::factory()->for($site)->create(),
        fn (ReportSubscription $subscription, Site $site) => [ReportSubscription::class, $site->id],
    ],
]);

it('lets a supervisor manage the users of their sites and themselves', function (): void {
    [$own, $foreign, $supervisor] = twoSites();
    $caller = supervisorCaller($supervisor);
    $colleague = User::factory()->create();
    $colleague->sites()->attach($own->id);
    // Also on a site the caller does not manage: seen, but not changed (that would take over their other site).
    $shared = User::factory()->supervisor()->create();
    $shared->sites()->attach([$own->id, $foreign->id]);
    $stranger = User::factory()->create();
    $stranger->sites()->attach($foreign->id);
    $unassigned = User::factory()->create();

    foreach (['view', 'update', 'delete'] as $ability) {
        expect(outcome(inspect($caller, $ability, $colleague)))->toBe('allow', $ability);
        expect(outcome(inspect($caller, $ability, $shared)))->toBe($ability === 'view' ? 'allow' : 403, $ability);
        expect(outcome(inspect($caller, $ability, $stranger)))->toBe(404, $ability);
        expect(outcome(inspect($caller, $ability, $unassigned)))->toBe(404, $ability);
    }
    expect(outcome(inspect($caller, 'view', $supervisor)))->toBe('allow');
    expect(outcome(inspect($caller, 'update', $supervisor)))->toBe('allow');
    expect(outcome(inspect($caller, 'delete', $supervisor)))->toBe(403);
    expect(outcome(inspect($caller, 'create', [User::class, [$own->id]])))->toBe('allow');
    expect(outcome(inspect($caller, 'create', [User::class, [$own->id, $foreign->id]])))->toBe(403);
});

it('keeps the acting supervisor on the site phone\'s site', function (): void {
    [$own, $other, $supervisor] = twoSites();
    $supervisor->sites()->attach($other->id);
    $device = Device::factory()->for($own)->create();
    $caller = deviceCaller($device, $supervisor);
    $guardElsewhere = User::factory()->create();
    $guardElsewhere->sites()->attach($other->id);

    expect(outcome(inspect($caller, 'update', $own)))->toBe('allow');
    expect(outcome(inspect($caller, 'update', $other)))->toBe(404);
    expect(outcome(inspect($caller, 'update', Checkpoint::factory()->for($other)->create())))->toBe(404);
    expect(outcome(inspect($caller, 'create', [Checkpoint::class, $other->id])))->toBe(403);
    expect(outcome(inspect($caller, 'update', $guardElsewhere)))->toBe(404);
    expect(outcome(inspect($caller, 'update', $supervisor)))->toBe('allow');
    expect(outcome(inspect($caller, 'create', Site::class)))->toBe(403);
});

it('lets the site phone itself configure nothing', function (): void {
    $device = Device::factory()->create();
    $site = $device->site()->firstOrFail();
    $caller = deviceCaller($device);

    expect(outcome(inspect($caller, 'view', $site)))->toBe(403);
    expect(outcome(inspect($caller, 'viewAny', Checkpoint::class)))->toBe(403);
    expect(outcome(inspect($caller, 'create', [Checkpoint::class, $site->id])))->toBe(403);
    expect($caller->siteIds())->toBe([$site->id]);
});

it('denies everything without a caller', function (): void {
    expect(app(Gate::class)->inspect('view', Site::factory()->create())->allowed())->toBeFalse();
});

it('keeps its policies out of the host application\'s gate', function (): void {
    HostGate::before(fn (Illuminate\Foundation\Auth\User $user): bool => true);
    $site = Site::factory()->create();

    expect(HostGate::getPolicyFor($site))->toBeNull();
    expect(outcome(inspect(supervisorCaller(supervisorOf()), 'view', $site)))->toBe(404);
});

it('has a policy for every configurable model', function (): void {
    foreach ([Site::class, User::class, Checkpoint::class, Route::class, Schedule::class, ReportSubscription::class] as $model) {
        expect(app(Gate::class)->getPolicyFor($model))->not->toBeNull($model);
    }
});
