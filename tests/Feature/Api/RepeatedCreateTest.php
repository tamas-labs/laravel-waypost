<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Waypost\Models\Checkpoint;
use Waypost\Models\Device;
use Waypost\Models\Model;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Route;
use Waypost\Models\RouteVersion;
use Waypost\Models\Schedule;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Time;
use Waypost\Support\Uuid;

use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

/** The creates of the outbox (M5-02), by the name of their record in the response. */
const REPEATED_CREATES = ['site', 'checkpoint', 'route', 'route_version', 'schedule', 'user', 'report_subscription'];

/**
 * A create request with the client-generated id, on $site of $supervisor (who also manages $other): its path, its
 * body, the model, the body repeated with another field that may differ (the record keeps its own), and the body
 * with another parent (null: a site has none).
 *
 * @return array{path: string, body: array<string, mixed>, model: class-string<Model>, changed: array<string, mixed>, otherParent: array{string, array<string, mixed>}|null}
 */
function repeatedCreate(string $record, string $id, Site $site, Site $other): array
{
    $route = Route::factory()->for($site)->create();
    $checkpoint = Checkpoint::factory()->for($site)->create();

    return match ($record) {
        'site' => [
            'path' => 'sites', 'body' => ['id' => $id, 'name' => 'Raktár B'], 'model' => Site::class,
            'changed' => ['name' => 'Raktár C'], 'otherParent' => null,
        ],
        'checkpoint' => [
            'path' => 'checkpoints', 'body' => ['id' => $id, 'site_id' => $site->id, 'name' => 'Kapu', 'tag_type' => 'qr'], 'model' => Checkpoint::class,
            'changed' => ['name' => 'Hátsó kapu'], 'otherParent' => ['checkpoints', ['site_id' => $other->id]],
        ],
        'route' => [
            'path' => 'routes', 'body' => ['id' => $id, 'site_id' => $site->id, 'name' => 'Éjszakai kör', 'order_mode' => 'fixed'], 'model' => Route::class,
            'changed' => ['order_mode' => 'free'], 'otherParent' => ['routes', ['site_id' => $other->id]],
        ],
        'route_version' => [
            'path' => "routes/{$route->id}/versions", 'body' => ['id' => $id, 'checkpoints' => [['checkpoint_id' => $checkpoint->id]]],
            'model' => RouteVersion::class, 'changed' => ['walk_duration_s' => 300],
            'otherParent' => ['routes/' . Route::factory()->for($site)->create()->id . '/versions', []],
        ],
        'schedule' => [
            'path' => 'schedules', 'body' => ['id' => $id, 'route_id' => $route->id, 'name' => 'Éjszaka', 'weekdays' => 127, 'mode' => 'list', 'start_times' => ['20:00']],
            'model' => Schedule::class, 'changed' => ['start_times' => ['21:00']],
            'otherParent' => ['schedules', ['route_id' => Route::factory()->for($site)->create()->id]],
        ],
        'user' => [
            'path' => 'users', 'body' => ['id' => $id, 'name' => 'Kiss Péter', 'role' => 'guard', 'pin' => '482913', 'site_ids' => [$site->id]], 'model' => User::class,
            'changed' => ['name' => 'Nagy Péter', 'pin' => '193728'],
            'otherParent' => ['users', ['role' => 'supervisor', 'email' => 'peter@example.com', 'password' => 'titkos-jelszo', 'pin' => '193728']],
        ],
        'report_subscription' => [
            'path' => 'report-subscriptions', 'body' => ['id' => $id, 'user_id' => supervisorOf($site, $other)->id, 'site_id' => $site->id, 'frequency' => 'daily'],
            'model' => ReportSubscription::class, 'changed' => ['frequency' => 'weekly'], 'otherParent' => ['report-subscriptions', ['site_id' => $other->id]],
        ],
        default => throw new LogicException("Unknown create: {$record}"),
    };
}

/** The rows of the model's table, deleted ones too. */
function rowCount(string $model): int
{
    /** @var class-string<Model> $model */
    return DB::table((new $model())->getTable())->count();
}

it('answers a create repeated with the same id with the record as it is now, and changes nothing', function (string $record): void {
    travelTo(Time::fromIso('2026-11-02T09:00:00.000Z'));
    [$site, $other] = [Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($site, $other);
    $id = Uuid::v7();
    $create = repeatedCreate($record, $id, $site, $other);
    $first = callApi('POST', $create['path'], $supervisor, $create['body'])->assertCreated();
    $rows = rowCount($create['model']);

    // Days later the outbox sends it again, with a new Idempotency-Key, and a field that differs.
    travelTo(Time::fromIso('2026-11-05T09:00:00.000Z'));
    $repeated = callApi('POST', $create['path'], $supervisor, [...$create['body'], ...$create['changed']])->assertOk();

    expect($repeated)->toMatchSchema('api/' . str_replace('_', '-', $record) . '.response');
    expect($repeated->json($record))->toBe($first->json($record));
    expect($repeated->json("{$record}.id"))->toBe($id);
    expect(rowCount($create['model']))->toBe($rows);
})->with(REPEATED_CREATES);

it('answers a repeated create through the site phone too', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $phone = Device::factory()->for($site)->create();
    $body = ['id' => Uuid::v7(), 'site_id' => $site->id, 'name' => 'Éjszakai kör', 'order_mode' => 'fixed'];
    $first = callApi('POST', 'routes', $supervisor, $body, $phone)->assertCreated();

    callApi('POST', 'routes', $supervisor, $body, $phone)->assertOk()->assertJsonPath('route', $first->json('route'));
});

it('answers with the changes made since the first attempt', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $body = ['id' => Uuid::v7(), 'site_id' => $site->id, 'name' => 'Kapu', 'tag_type' => 'qr'];
    $first = callApi('POST', 'checkpoints', $supervisor, $body)->assertCreated();
    callApi('PATCH', 'checkpoints/' . $body['id'], $supervisor, ['name' => 'Főkapu'])->assertOk();

    $repeated = callApi('POST', 'checkpoints', $supervisor, $body)->assertOk();

    expect($repeated->json('checkpoint'))->toMatchArray(['name' => 'Főkapu', 'qr_payload' => $first->json('checkpoint.qr_payload')]);
});

it('shows the route as it is now with a repeated version', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $route = Route::factory()->for($site)->create();
    [$a, $b] = [Checkpoint::factory()->for($site)->create(), Checkpoint::factory()->for($site)->create()];
    $body = ['id' => Uuid::v7(), 'checkpoints' => [['checkpoint_id' => $a->id]]];
    callApi('POST', "routes/{$route->id}/versions", $supervisor, $body)->assertCreated();
    $later = callApi('POST', "routes/{$route->id}/versions", $supervisor, ['checkpoints' => [['checkpoint_id' => $b->id]]])->json('route_version.id');
    callApi('DELETE', "checkpoints/{$a->id}", $supervisor)->assertOk();

    // The checkpoint of the version is deleted since: still the same version, not a validation error.
    $repeated = callApi('POST', "routes/{$route->id}/versions", $supervisor, $body)->assertOk();

    expect($repeated->json('route_version'))->toMatchArray(['id' => $body['id'], 'version' => 1]);
    expect($repeated->json('route_version_checkpoints.*.checkpoint_id'))->toBe([$a->id]);
    expect($repeated->json('route.current_version_id'))->toBe($later);
});

it('does not show a PIN the server generated again', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $body = ['id' => Uuid::v7(), 'name' => 'Kiss Péter', 'role' => 'guard', 'site_ids' => [$site->id]];
    $first = callApi('POST', 'users', $supervisor, $body)->assertCreated();
    expect($first->json('pin'))->toBeString();

    $repeated = callApi('POST', 'users', $supervisor, $body)->assertOk();

    expect($repeated->json())->not->toHaveKey('pin');
    expect($repeated->json('user'))->toBe($first->json('user'));
});

it('keeps the Idempotency-Key replay as it was', function (): void {
    $supervisor = User::factory()->supervisor()->create();
    $headers = ['Authorization' => 'Bearer ' . supervisorToken($supervisor), 'Idempotency-Key' => (string) Str::uuid()];
    $body = ['id' => Uuid::v7(), 'name' => 'Raktár B'];
    requestApi('POST', 'sites', $headers, $body)->assertCreated();

    requestApi('POST', 'sites', $headers, $body)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
});

it('refuses the id of a record with another parent', function (string $record): void {
    [$site, $other] = [Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($site, $other);
    $create = repeatedCreate($record, Uuid::v7(), $site, $other);
    callApi('POST', $create['path'], $supervisor, $create['body'])->assertCreated();
    [$path, $parent] = $create['otherParent'] ?? throw new LogicException('No parent.');
    $rows = rowCount($create['model']);

    $response = callApi('POST', $path, $supervisor, [...$create['body'], ...$parent])->assertConflict();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error'))->toBe([
        'code' => 'conflict',
        'message' => 'Ezzel az azonosítóval már létezik rekord.',
        'details' => ['id' => ['Foglalt azonosító.']],
    ]);
    expect(rowCount($create['model']))->toBe($rows);
})->with(array_values(array_diff(REPEATED_CREATES, ['site'])));

it('refuses the id of a deleted record', function (string $record): void {
    [$site, $other] = [Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($site, $other);
    $create = repeatedCreate($record, $id = Uuid::v7(), $site, $other);
    callApi('POST', $create['path'], $supervisor, $create['body'])->assertCreated();
    $create['model']::query()->findOrFail($id)->delete();

    $response = callApi('POST', $create['path'], $supervisor, $create['body'])->assertConflict();

    expect($response->json('error'))->toBe([
        'code' => 'conflict',
        'message' => 'Ezzel az azonosítóval már létrejött egy rekord, de azóta törölték.',
        'details' => ['id' => ['Törölt rekord azonosítója.']],
    ]);
})->with(array_values(array_diff(REPEATED_CREATES, ['route_version'])));

it('refuses the id of a record the caller does not see', function (string $record): void {
    [$site, $other] = [Site::factory()->create(), Site::factory()->create()];
    $create = repeatedCreate($record, Uuid::v7(), $site, $other);
    callApi('POST', $create['path'], supervisorOf($site), $create['body'])->assertCreated();
    $stranger = supervisorOf($other);
    $body = match ($record) {
        'site' => $create['body'],
        'user' => [...$create['body'], 'site_ids' => [$other->id], 'pin' => '193728'],
        default => throw new LogicException("Unknown create: {$record}"),
    };

    $response = callApi('POST', $create['path'], $stranger, $body)->assertConflict();

    expect($response->json('error.details'))->toBe(['id' => ['Foglalt azonosító.']]);
})->with(['site', 'user']);
