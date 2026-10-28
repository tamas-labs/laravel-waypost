<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Waypost\Models\Checkpoint;
use Waypost\Models\Device;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Route;
use Waypost\Models\RouteVersion;
use Waypost\Models\RouteVersionCheckpoint;
use Waypost\Models\Schedule;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Time;
use Waypost\Sync\Pull;

use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

beforeEach(fn () => travelTo(Time::fromIso('2026-10-28T09:00:00.000Z')));

/** Past the settling time of the pull, so what changed until now comes. */
function settle(): void
{
    travelTo(Time::now()->addMilliseconds(Pull::SETTLE_MS + 1));
}

/**
 * The ids of a "changes" list.
 *
 * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
 * @return list<string>
 */
function changed(TestResponse $response, string $list): array
{
    return array_column($response->json("changes.{$list}"), 'id');
}

/** @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response */
function recordCount(TestResponse $response): int
{
    return array_sum(array_map(count(...), $response->json('changes'))) + array_sum(array_map(count(...), $response->json('deleted')));
}

it('gives the site phone the full state of its site, with the PINs', function (): void {
    $site = Site::factory()->create();
    $supervisor = User::factory()->supervisor()->withPin('111111')->create();
    $guard = User::factory()->withPin('222222')->create();
    $site->users()->attach([$supervisor->id, $guard->id]);
    $checkpoint = Checkpoint::factory()->for($site)->create();
    $route = Route::factory()->for($site)->create();
    $version = RouteVersion::factory()->for($route)->create();
    RouteVersionCheckpoint::factory()->create(['route_version_id' => $version->id, 'checkpoint_id' => $checkpoint->id, 'position' => 1]);
    $route->update(['current_version_id' => $version->id]);
    $schedule = Schedule::factory()->for($route)->create();
    $subscription = ReportSubscription::factory()->for($site)->for($supervisor)->create();
    Device::factory()->for($site)->revoked()->create();
    $phone = Device::factory()->for($site)->create();
    Checkpoint::factory()->create();
    Device::factory()->create();
    User::factory()->create()->sites()->attach(Site::factory()->create()->id);
    settle();

    $response = pullAs($phone)->assertOk();

    expect($response)->toMatchSchema('api/sync-pull.response');
    expect($response->json())->toMatchArray(['has_more' => false, 'reset' => true]);
    expect(changed($response, 'sites'))->toBe([$site->id]);
    expect(changed($response, 'users'))->toEqualCanonicalizing([$supervisor->id, $guard->id]);
    expect($response->json('changes.users.*.pin_hash'))->each->not->toBeNull();
    expect($response->json('changes.users.*.site_ids'))->each->toBe([$site->id]);
    expect(changed($response, 'devices'))->toBe([$phone->id]);
    expect(changed($response, 'checkpoints'))->toBe([$checkpoint->id]);
    expect(changed($response, 'routes'))->toBe([$route->id]);
    expect($response->json('changes.routes.0.current_version_id'))->toBe($version->id);
    expect(changed($response, 'route_versions'))->toBe([$version->id]);
    expect($response->json('changes.route_version_checkpoints'))->toBe([
        ['route_version_id' => $version->id, 'checkpoint_id' => $checkpoint->id, 'position' => 1, 'walk_time_from_prev_s' => null],
    ]);
    expect(changed($response, 'schedules'))->toBe([$schedule->id]);
    expect(changed($response, 'report_subscriptions'))->toBe([$subscription->id]);
    expect(array_merge(...array_values($response->json('deleted'))))->toBe([]);
});

it('notes when the phone was last seen, without making it a change', function (): void {
    $phone = Device::factory()->create();
    settle();
    $cursor = pullAs($phone)->json('cursor');
    settle();

    $response = pullAs($phone, $cursor)->assertOk();

    expect(changed($response, 'devices'))->toBe([]);
    expect(Time::iso($phone->refresh()->last_seen_at ?? throw new LogicException('Not seen.')))->toBe(Time::iso(Time::now()));
    expect($phone->updated_at?->lessThan(Time::now()))->toBeTrue();
});

it('gives a supervisor every site of theirs, without the PINs', function (): void {
    [$a, $b, $foreign] = [Site::factory()->create(), Site::factory()->create(), Site::factory()->create()];
    $supervisor = User::factory()->supervisor()->withPin('111111')->create();
    $supervisor->sites()->attach([$a->id, $b->id]);
    $guard = User::factory()->withPin('222222')->create();
    $guard->sites()->attach([$b->id, $foreign->id]);
    Checkpoint::factory()->for($foreign)->create();
    settle();

    $response = pullAs($supervisor)->assertOk();

    expect($response)->toMatchSchema('api/sync-pull.response');
    expect(changed($response, 'sites'))->toEqualCanonicalizing([$a->id, $b->id]);
    expect(changed($response, 'users'))->toEqualCanonicalizing([$supervisor->id, $guard->id]);
    expect($response->json('changes.users.*.pin_hash'))->each->toBeNull();
    $users = array_column($response->json('changes.users'), 'site_ids', 'id');
    expect($users[$guard->id])->toBe([$b->id]);
    expect(changed($response, 'checkpoints'))->toBe([]);
});

it('gives a supervisor without sites only themselves', function (): void {
    $supervisor = User::factory()->supervisor()->create();
    Site::factory()->create();
    settle();

    $response = pullAs($supervisor)->assertOk();

    expect($response)->toMatchSchema('api/sync-pull.response');
    expect(changed($response, 'users'))->toBe([$supervisor->id]);
    expect(changed($response, 'sites'))->toBe([]);
});

it('gives a cursor for an empty state, and accepts an empty cursor as none', function (): void {
    $phone = Device::factory()->create();

    $response = pullAs($phone, '')->assertOk();

    expect($response)->toMatchSchema('api/sync-pull.response');
    expect($response->json())->toMatchArray(['has_more' => false, 'reset' => true]);
    expect(recordCount($response))->toBe(0);
    pullAs($phone, $response->json('cursor'))->assertOk()->assertJsonPath('reset', false);
});

it('sends what changed in the last seconds only once it settled', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    settle();
    $cursor = pullAs($supervisor)->json('cursor');
    $checkpoint = Checkpoint::factory()->for($site)->create();

    $now = pullAs($supervisor, $cursor)->assertOk();
    expect(changed($now, 'checkpoints'))->toBe([]);
    expect($now->json('has_more'))->toBeFalse();

    settle();
    expect(changed(pullAs($supervisor, $now->json('cursor')), 'checkpoints'))->toBe([$checkpoint->id]);
});

it('brings changes and deletions since the cursor', function (): void {
    [$site, $other] = [Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($site);
    [$detached, $removed] = [User::factory()->create(), User::factory()->create()];
    $site->users()->attach([$detached->id, $removed->id]);
    $stranger = User::factory()->create();
    $stranger->sites()->attach($other->id);
    [$renamed, $kept] = [Checkpoint::factory()->for($site)->create(), Checkpoint::factory()->for($site)->create()];
    $route = Route::factory()->for($site)->create();
    $schedule = Schedule::factory()->for($route)->create();
    $subscription = ReportSubscription::factory()->for($site)->for($supervisor)->create();
    $phone = Device::factory()->for($site)->create();
    settle();
    $full = pullAs($supervisor)->assertOk();
    expect(changed($full, 'users'))->toEqualCanonicalizing([$supervisor->id, $detached->id, $removed->id]);
    expect(changed($full, 'checkpoints'))->toEqualCanonicalizing([$renamed->id, $kept->id]);
    travelTo(Time::now()->addMinute());

    callApi('DELETE', "sites/{$site->id}/users/{$detached->id}", $supervisor)->assertOk();
    callApi('DELETE', "users/{$removed->id}", $supervisor)->assertOk();
    callApi('PATCH', "checkpoints/{$renamed->id}", $supervisor, ['name' => 'Új név'])->assertOk();
    callApi('DELETE', "routes/{$route->id}", $supervisor)->assertOk();
    callApi('DELETE', "report-subscriptions/{$subscription->id}", $supervisor)->assertOk();
    $phone->update(['revoked_at' => Time::now()]);
    $stranger->update(['name' => 'Valaki más']);
    settle();

    $response = pullAs($supervisor, $full->json('cursor'))->assertOk();

    expect($response)->toMatchSchema('api/sync-pull.response');
    expect($response->json())->toMatchArray(['has_more' => false, 'reset' => false]);
    expect(changed($response, 'checkpoints'))->toBe([$renamed->id]);
    expect($response->json('changes.checkpoints.0.name'))->toBe('Új név');
    expect(changed($response, 'users'))->toBe([]);
    expect($response->json('deleted.users'))->toEqualCanonicalizing([$detached->id, $removed->id]);
    expect($response->json('deleted.routes'))->toBe([$route->id]);
    expect($response->json('deleted.schedules'))->toBe([$schedule->id]);
    expect($response->json('deleted.report_subscriptions'))->toBe([$subscription->id]);
    expect($response->json('deleted.devices'))->toBe([$phone->id]);
    expect($response->json('deleted.checkpoints'))->toBe([]);
    expect(recordCount($response))->toBe(7);

    $again = pullAs($supervisor, $response->json('cursor'))->assertOk();
    expect(recordCount($again))->toBe(0);
    expect($again->json('cursor'))->toBe($response->json('cursor'));
});

it('lists a detached user as deleted only for those who no longer see them', function (): void {
    [$site, $other] = [Site::factory()->create(), Site::factory()->create()];
    $both = supervisorOf($site, $other);
    $phone = Device::factory()->for($site)->create();
    $guard = User::factory()->create();
    $guard->sites()->attach([$site->id, $other->id]);
    settle();
    [$phoneCursor, $supervisorCursor] = [pullAs($phone)->json('cursor'), pullAs($both)->json('cursor')];

    callApi('DELETE', "sites/{$site->id}/users/{$guard->id}", $both)->assertOk();
    settle();

    $forPhone = pullAs($phone, $phoneCursor)->assertOk();
    expect($forPhone->json('deleted.users'))->toBe([$guard->id]);
    expect(changed($forPhone, 'users'))->toBe([]);

    $forSupervisor = pullAs($both, $supervisorCursor)->assertOk();
    expect($forSupervisor->json('deleted.users'))->toBe([]);
    expect($forSupervisor->json('changes.users'))->toHaveCount(1);
    expect($forSupervisor->json('changes.users.0'))->toMatchArray(['id' => $guard->id, 'site_ids' => [$other->id]]);
});

it('pages through more than 500 records, also between records changed at the same moment', function (): void {
    $site = Site::factory()->create();
    $phone = Device::factory()->for($site)->create();
    $checkpoints = Checkpoint::factory()->count(520)->for($site)->create();
    settle();

    $first = pullAs($phone)->assertOk();
    expect($first)->toMatchSchema('api/sync-pull.response');
    expect($first->json())->toMatchArray(['has_more' => true, 'reset' => true]);
    expect(recordCount($first))->toBe(Pull::PAGE_SIZE);

    $second = pullAs($phone, $first->json('cursor'))->assertOk();
    expect($second)->toMatchSchema('api/sync-pull.response');
    expect($second->json())->toMatchArray(['has_more' => false, 'reset' => false]);
    expect(recordCount($second))->toBe(22);

    $sent = [...changed($first, 'checkpoints'), ...changed($second, 'checkpoints')];
    expect($sent)->toHaveCount(520);
    expect($sent)->toEqualCanonicalizing($checkpoints->modelKeys());
    expect([...changed($first, 'sites'), ...changed($second, 'sites')])->toBe([$site->id]);
    expect([...changed($first, 'devices'), ...changed($second, 'devices')])->toBe([$phone->id]);
});

it('never splits a route version from its checkpoints', function (): void {
    $site = Site::factory()->create();
    $phone = Device::factory()->for($site)->create();
    $route = Route::factory()->for($site)->create();
    $checkpoints = Checkpoint::factory()->count(Pull::PAGE_SIZE - 6)->for($site)->create();
    travelTo(Time::now()->addSecond());
    $version = RouteVersion::factory()->for($route)->create();
    foreach ($checkpoints->take(4)->values() as $index => $checkpoint) {
        RouteVersionCheckpoint::factory()->create(['route_version_id' => $version->id, 'checkpoint_id' => $checkpoint->id, 'position' => $index + 1]);
    }
    settle();

    // site, phone, route and 494 checkpoints: 497; the version with its 4 checkpoints would make 502.
    $first = pullAs($phone)->assertOk();
    expect($first->json('has_more'))->toBeTrue();
    expect(recordCount($first))->toBe(497);
    expect(changed($first, 'route_versions'))->toBe([]);

    $second = pullAs($phone, $first->json('cursor'))->assertOk();
    expect($second->json('has_more'))->toBeFalse();
    expect(changed($second, 'route_versions'))->toBe([$version->id]);
    expect($second->json('changes.route_version_checkpoints.*.position'))->toBe([1, 2, 3, 4]);
});

it('starts again from the full state when the supervisor\'s sites change', function (): void {
    [$own, $joined] = [Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($own);
    $old = Checkpoint::factory()->for($joined)->create();
    settle();
    $first = pullAs($supervisor)->json('cursor');
    travelTo(Time::now()->addHour());

    callApi('POST', "sites/{$joined->id}/users", supervisorOf($own, $joined), ['user_id' => $supervisor->id])->assertOk();
    settle();
    $assigned = pullAs($supervisor, $first)->assertOk();

    expect($assigned)->toMatchSchema('api/sync-pull.response');
    expect($assigned->json('reset'))->toBeTrue();
    expect(changed($assigned, 'sites'))->toEqualCanonicalizing([$own->id, $joined->id]);
    expect(changed($assigned, 'checkpoints'))->toBe([$old->id]);

    callApi('DELETE', "sites/{$joined->id}/users/{$supervisor->id}", $supervisor)->assertOk();
    settle();
    $detached = pullAs($supervisor, $assigned->json('cursor'))->assertOk();

    expect($detached->json('reset'))->toBeTrue();
    expect(changed($detached, 'sites'))->toBe([$own->id]);
    expect(changed($detached, 'checkpoints'))->toBe([]);
});

it('refuses a cursor it did not give out', function (string $cursor): void {
    $response = pullAs(Device::factory()->create(), $cursor)->assertUnprocessable();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.details'))->toBe(['cursor' => ['Hibás kurzor; kurzor nélkül a teljes állapot kérhető le.']]);
})->with([
    'not base64' => ['nem kurzor!'],
    'another text' => [rtrim(strtr(base64_encode('2026-10-28T09:00:00.000Z_x'), '+/', '-_'), '=')],
    'an impossible time' => [rtrim(strtr(base64_encode('2026-02-30T09:00:00.000Z_00000000-0000-0000-0000-000000000000_0123456789abcdef'), '+/', '-_'), '=')],
]);

it('refuses a cursor in an array', function (): void {
    $token = deviceToken(Device::factory()->create());

    requestApi('GET', 'sync/pull?cursor[]=x', ['Authorization' => "Bearer {$token}"])
        ->assertUnprocessable()->assertJsonPath('error.details', ['cursor' => ['Hibás kurzor; kurzor nélkül a teljes állapot kérhető le.']]);
});

it('answers only the site phone and supervisors with their own token', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $phone = Device::factory()->for($site)->create();
    $revoked = Device::factory()->for($site)->revoked()->create();

    callApi('GET', 'sync/pull', $supervisor, phone: $phone)->assertForbidden();
    pullAs($revoked)->assertUnauthorized()->assertJsonPath('error.code', 'device_revoked');
    requestApi('GET', 'sync/pull', [])->assertUnauthorized();
    expect($phone->refresh()->last_seen_at)->toBeNull();
});
