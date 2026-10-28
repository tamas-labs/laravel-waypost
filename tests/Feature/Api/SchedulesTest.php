<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Waypost\Models\Route;
use Waypost\Models\RouteVersion;
use Waypost\Models\Schedule;
use Waypost\Models\Site;
use Waypost\Models\User;

pest()->use(RefreshDatabase::class);

/** @return array{Route, User} */
function routeOfSupervisor(?int $walkDurationS = null, bool $withVersion = true): array
{
    $site = Site::factory()->create();
    $route = Route::factory()->for($site)->create(['current_version_id' => null]);
    if ($withVersion) {
        $route->update(['current_version_id' => RouteVersion::factory()->for($route)->create(['walk_duration_s' => $walkDurationS])->id]);
    }

    return [$route, supervisorOf($site)];
}

it('creates a list schedule', function (): void {
    [$route, $supervisor] = routeOfSupervisor(840);

    $response = callApi('POST', 'schedules', $supervisor, [
        'route_id' => $route->id, 'name' => 'Hétvége', 'weekdays' => 96, 'mode' => 'list', 'start_times' => ['22:00', '00:00', '02:00'],
    ])->assertCreated();

    expect($response)->toMatchSchema('api/schedule.response');
    expect($response->json('schedule'))->toMatchArray([
        'route_id' => $route->id, 'weekdays' => 96, 'mode' => 'list', 'start_times' => ['22:00', '00:00', '02:00'],
        'interval_from' => null, 'interval_to' => null, 'interval_every_min' => null, 'time_limit_min' => 25, 'active' => true,
    ]);
});

it('creates an interval schedule', function (): void {
    [$route, $supervisor] = routeOfSupervisor();

    $response = callApi('POST', 'schedules', $supervisor, [
        'route_id' => $route->id, 'name' => 'Hétköznap éjjel', 'weekdays' => 31, 'mode' => 'interval',
        'interval_from' => '20:00', 'interval_to' => '08:00', 'interval_every_min' => 60, 'time_limit_min' => 15,
    ])->assertCreated();

    expect($response)->toMatchSchema('api/schedule.response');
    expect($response->json('schedule'))->toMatchArray([
        'start_times' => null, 'interval_from' => '20:00', 'interval_to' => '08:00', 'interval_every_min' => 60, 'time_limit_min' => 15,
    ]);
    expect(Schedule::query()->findOrFail((string) $response->json('schedule.id'))->interval_to)->toBe('08:00');
});

it('takes the default time limit from the current version of the route', function (?int $walk, bool $withVersion, ?int $expected, array $given): void {
    [$route, $supervisor] = routeOfSupervisor($walk, $withVersion);

    $response = callApi('POST', 'schedules', $supervisor, [
        'route_id' => $route->id, 'name' => 'Éjjel', 'weekdays' => 127, 'mode' => 'list', 'start_times' => ['20:00'], ...$given,
    ])->assertCreated();

    expect($response->json('schedule.time_limit_min'))->toBe($expected);
})->with([
    'measured walk' => [3600, true, 90, []],
    'walk not measured' => [null, true, 20, []],
    'no version yet' => [null, false, 20, []],
    'no limit asked for' => [3600, true, null, ['time_limit_min' => null]],
    'given' => [3600, true, 30, ['time_limit_min' => 30]],
]);

it('refuses what the schema cannot express', function (array $fields, string $field, string $message): void {
    [$route, $supervisor] = routeOfSupervisor();

    $response = callApi('POST', 'schedules', $supervisor, ['route_id' => $route->id, 'name' => 'Éjjel', 'weekdays' => 127, ...$fields])->assertUnprocessable();

    expect($response->json('error.details'))->toBe([$field => [$message]]);
})->with([
    'two steps to the next day' => [['mode' => 'list', 'start_times' => ['22:00', '02:00', '01:00']], 'start_times', 'Az időpontok legfeljebb egyszer léphetnek át a következő napra, és akkor az utolsó korábbi legyen az elsőnél.'],
    'a window of a day or more' => [['mode' => 'list', 'start_times' => ['22:00', '02:00', '23:00']], 'start_times', 'Az időpontok legfeljebb egyszer léphetnek át a következő napra, és akkor az utolsó korábbi legyen az elsőnél.'],
    'an empty interval' => [['mode' => 'interval', 'interval_from' => '20:00', 'interval_to' => '20:00', 'interval_every_min' => 60], 'interval_to', 'Nem lehet ugyanaz, mint a kezdete.'],
]);

it('validates the fields of the mode against the schema', function (): void {
    [$route, $supervisor] = routeOfSupervisor();

    $response = callApi('POST', 'schedules', $supervisor, [
        'route_id' => $route->id, 'name' => 'Éjjel', 'weekdays' => 127, 'mode' => 'interval', 'interval_from' => '20:00', 'start_times' => ['20:00'],
    ])->assertUnprocessable();

    expect($response->json('error.details'))->toBe([
        'interval_to' => ['Kötelező mező.'],
        'interval_every_min' => ['Kötelező mező.'],
        'start_times' => ['Ez a mező nem megengedett.'],
    ]);
});

it('refuses a route the caller does not see', function (): void {
    [, $supervisor] = routeOfSupervisor();

    $response = callApi('POST', 'schedules', $supervisor, [
        'route_id' => Route::factory()->create()->id, 'name' => 'Éjjel', 'weekdays' => 127, 'mode' => 'list', 'start_times' => ['20:00'],
    ])->assertUnprocessable();

    expect($response->json('error.details'))->toBe(['route_id' => ['Nincs ilyen útvonal.']]);
});

it('switches the mode and clears the fields of the old one', function (): void {
    [$route, $supervisor] = routeOfSupervisor();
    $schedule = Schedule::factory()->for($route)->interval()->create();

    $response = callApi('PATCH', "schedules/{$schedule->id}", $supervisor, ['mode' => 'list', 'start_times' => ['20:00', '23:00']])->assertOk();

    expect($response)->toMatchSchema('api/schedule.response');
    expect($response->json('schedule'))->toMatchArray([
        'mode' => 'list', 'start_times' => ['20:00', '23:00'], 'interval_from' => null, 'interval_to' => null, 'interval_every_min' => null,
    ]);
});

it('changes only the fields of the current mode without a new mode', function (): void {
    [$route, $supervisor] = routeOfSupervisor();
    $schedule = Schedule::factory()->for($route)->interval()->create();

    callApi('PATCH', "schedules/{$schedule->id}", $supervisor, ['interval_every_min' => 30])->assertOk()->assertJsonPath('schedule.interval_every_min', 30);

    $response = callApi('PATCH', "schedules/{$schedule->id}", $supervisor, ['start_times' => ['20:00']])->assertUnprocessable();
    expect($response->json('error.details'))->toBe(['start_times' => ['Csak lista módban adható meg.']]);
    $response = callApi('PATCH', "schedules/{$schedule->id}", $supervisor, ['interval_to' => '20:00'])->assertUnprocessable();
    expect($response->json('error.details'))->toBe(['interval_to' => ['Nem lehet ugyanaz, mint a kezdete.']]);
});

it('lists, shows and deletes the schedules of the caller\'s routes', function (): void {
    [$route, $supervisor] = routeOfSupervisor();
    $schedule = Schedule::factory()->for($route)->create(['name' => 'A']);
    $second = Schedule::factory()->for(Route::factory()->state(['site_id' => $route->site_id]))->create(['name' => 'B']);
    $foreign = Schedule::factory()->create();

    $list = callApi('GET', 'schedules', $supervisor)->assertOk();
    expect($list)->toMatchSchema('api/schedule-list.response');
    expect($list->json('schedules.*.id'))->toBe([$schedule->id, $second->id]);
    expect(callApi('GET', "schedules?route_id={$route->id}", $supervisor)->json('schedules.*.id'))->toBe([$schedule->id]);

    expect(callApi('GET', "schedules/{$schedule->id}", $supervisor)->assertOk())->toMatchSchema('api/schedule.response');
    callApi('GET', "schedules/{$foreign->id}", $supervisor)->assertNotFound();
    callApi('DELETE', "schedules/{$schedule->id}", $supervisor)->assertOk();
    expect($schedule->refresh()->trashed())->toBeTrue();
});
