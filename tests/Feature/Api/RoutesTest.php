<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Waypost\Models\Checkpoint;
use Waypost\Models\Device;
use Waypost\Models\Route;
use Waypost\Models\RouteVersion;
use Waypost\Models\RouteVersionCheckpoint;
use Waypost\Models\Schedule;
use Waypost\Models\Site;
use Waypost\Models\User;

pest()->use(RefreshDatabase::class);

/** @return array{Site, User, Route, Checkpoint, Checkpoint} */
function routeWithCheckpoints(): array
{
    $site = Site::factory()->create();

    $route = Route::factory()->for($site)->create(['current_version_id' => null]);

    return [$site, supervisorOf($site), $route, Checkpoint::factory()->for($site)->create(), Checkpoint::factory()->for($site)->create()];
}

it('creates, lists, updates and shows a route', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    Route::factory()->create();

    $created = callApi('POST', 'routes', $supervisor, ['site_id' => $site->id, 'name' => 'Éjszakai kör', 'order_mode' => 'fixed'])->assertCreated();
    expect($created)->toMatchSchema('api/route.response');
    expect($created->json('route'))->toMatchArray(['site_id' => $site->id, 'order_mode' => 'fixed', 'current_version_id' => null, 'active' => true]);
    $id = $created->json('route.id');

    $list = callApi('GET', 'routes', $supervisor)->assertOk();
    expect($list)->toMatchSchema('api/route-list.response');
    expect($list->json('routes.*.id'))->toBe([$id]);

    $updated = callApi('PATCH', "routes/{$id}", $supervisor, ['order_mode' => 'free', 'active' => false])->assertOk();
    expect($updated->json('route'))->toMatchArray(['order_mode' => 'free', 'active' => false]);
    expect(callApi('GET', "routes/{$id}", $supervisor)->assertOk())->toMatchSchema('api/route.response');
});

it('keeps routes on the caller\'s sites', function (): void {
    [$own, $foreign] = [Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($own);
    $theirs = Route::factory()->for($foreign)->create();

    callApi('POST', 'routes', $supervisor, ['site_id' => $foreign->id, 'name' => 'Kör', 'order_mode' => 'free'])->assertForbidden();
    callApi('GET', "routes/{$theirs->id}", $supervisor)->assertNotFound();
    callApi('GET', "routes/{$theirs->id}/versions", $supervisor)->assertNotFound();
    callApi('POST', "routes/{$theirs->id}/versions", $supervisor, ['checkpoints' => [['checkpoint_id' => Checkpoint::factory()->for($foreign)->create()->id]]])->assertNotFound();
});

it('deletes a route with its schedules', function (): void {
    [, $supervisor, $route] = routeWithCheckpoints();
    $schedule = Schedule::factory()->for($route)->create();

    expect(callApi('DELETE', "routes/{$route->id}", $supervisor)->assertOk())->toMatchSchema('api/deleted.response');

    expect($route->refresh()->trashed())->toBeTrue();
    expect($schedule->refresh()->trashed())->toBeTrue();
});

it('creates numbered versions and makes the newest the current one', function (): void {
    [, $supervisor, $route, $a, $b] = routeWithCheckpoints();

    $first = callApi('POST', "routes/{$route->id}/versions", $supervisor, [
        'walk_duration_s' => 840,
        'checkpoints' => [['checkpoint_id' => $a->id, 'walk_time_from_prev_s' => null], ['checkpoint_id' => $b->id, 'walk_time_from_prev_s' => 95]],
    ])->assertCreated();

    expect($first)->toMatchSchema('api/route-version.response');
    $firstId = $first->json('route_version.id');
    expect($first->json('route_version'))->toMatchArray(['route_id' => $route->id, 'version' => 1, 'walk_duration_s' => 840, 'created_by' => $supervisor->id]);
    expect($first->json('route.current_version_id'))->toBe($firstId);
    expect($first->json('route_version_checkpoints'))->toBe([
        ['route_version_id' => $firstId, 'checkpoint_id' => $a->id, 'position' => 1, 'walk_time_from_prev_s' => null],
        ['route_version_id' => $firstId, 'checkpoint_id' => $b->id, 'position' => 2, 'walk_time_from_prev_s' => 95],
    ]);

    $second = callApi('POST', "routes/{$route->id}/versions", $supervisor, ['checkpoints' => [['checkpoint_id' => $b->id], ['checkpoint_id' => $a->id]]])->assertCreated();
    expect($second->json('route_version'))->toMatchArray(['version' => 2, 'walk_duration_s' => null]);
    expect($route->refresh()->current_version_id)->toBe($second->json('route_version.id'));

    $list = callApi('GET', "routes/{$route->id}/versions", $supervisor)->assertOk();
    expect($list)->toMatchSchema('api/route-version-list.response');
    expect($list->json('route_versions.*.version'))->toBe([1, 2]);
    expect($list->json('route_version_checkpoints.*.checkpoint_id'))->toBe([$a->id, $b->id, $b->id, $a->id]);

    $show = callApi('GET', "routes/{$route->id}/versions/{$firstId}", $supervisor)->assertOk();
    expect($show)->toMatchSchema('api/route-version.response');
    expect($show->json('route_version.version'))->toBe(1);
});

it('creates a version through the site phone in the acting supervisor\'s name', function (): void {
    [$site, $supervisor, $route, $a] = routeWithCheckpoints();

    $response = callApi('POST', "routes/{$route->id}/versions", $supervisor, ['checkpoints' => [['checkpoint_id' => $a->id]]], Device::factory()->for($site)->create())
        ->assertCreated();

    expect($response->json('route_version.created_by'))->toBe($supervisor->id);
});

it('checks the checkpoints of a new version', function (array $points, array $details): void {
    [$site, $supervisor, $route, $a, $b] = routeWithCheckpoints();
    $foreign = Checkpoint::factory()->create();
    $deleted = Checkpoint::factory()->for($site)->create();
    $deleted->delete();
    $ids = ['a' => $a->id, 'b' => $b->id, 'foreign' => $foreign->id, 'deleted' => $deleted->id];
    $body = ['checkpoints' => array_map(fn (array $point) => ['checkpoint_id' => $ids[$point[0]], 'walk_time_from_prev_s' => $point[1]], $points)];

    $response = callApi('POST', "routes/{$route->id}/versions", $supervisor, $body)->assertUnprocessable();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.details'))->toBe($details);
    expect(RouteVersion::query()->count())->toBe(0);
})->with([
    'another site' => [[['a', null], ['foreign', 10]], ['checkpoints.1.checkpoint_id' => ['Nincs ilyen pont ezen a telephelyen.']]],
    'deleted' => [[['deleted', null]], ['checkpoints.0.checkpoint_id' => ['Nincs ilyen pont ezen a telephelyen.']]],
    'twice' => [[['a', null], ['b', 10], ['a', 10]], ['checkpoints.2.checkpoint_id' => ['Ez a pont már szerepel az útvonalban.']]],
    'walk time at the first position' => [[['a', 10]], ['checkpoints.0.walk_time_from_prev_s' => ['Az első pontnál nem adható meg.']]],
]);

it('never changes a version', function (string $method): void {
    [, $supervisor, $route, $a] = routeWithCheckpoints();
    $version = callApi('POST', "routes/{$route->id}/versions", $supervisor, ['checkpoints' => [['checkpoint_id' => $a->id]]])->json('route_version.id');

    $response = callApi($method, "routes/{$route->id}/versions/{$version}", $supervisor, ['walk_duration_s' => 1])->assertStatus(405);

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error'))->toBe(['code' => 'bad_request', 'message' => 'Ez a végpont ezt a kérésmódot nem támogatja.']);
})->with(['PATCH', 'PUT', 'DELETE']);

it('refuses to update a version or its checkpoints in the models', function (): void {
    $version = RouteVersion::factory()->create(['walk_duration_s' => 600]);
    $point = RouteVersionCheckpoint::factory()->create(['route_version_id' => $version->id, 'position' => 1]);

    expect(fn () => $version->update(['walk_duration_s' => 700]))->toThrow(LogicException::class, 'A route version never changes; create a new version.');
    expect(fn () => $point->update(['walk_time_from_prev_s' => 10]))->toThrow(LogicException::class);
    expect($version->refresh()->walk_duration_s)->toBe(600);
});

it('answers not_found for a version of another route', function (): void {
    [$site, $supervisor, $route] = routeWithCheckpoints();
    $other = RouteVersion::factory()->for(Route::factory()->for($site))->create();

    callApi('GET', "routes/{$route->id}/versions/{$other->id}", $supervisor)->assertNotFound();
});
