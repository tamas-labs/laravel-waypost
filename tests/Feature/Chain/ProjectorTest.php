<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Waypost\Chain\Projector;
use Waypost\Enums\RoundStatus;
use Waypost\Enums\ShiftClosedBy;
use Waypost\Enums\VerifyStatus;
use Waypost\Models\ChainEntry;
use Waypost\Models\Checkpoint;
use Waypost\Models\Device;
use Waypost\Models\Incident;
use Waypost\Models\Media;
use Waypost\Models\Round;
use Waypost\Models\Route;
use Waypost\Models\RouteVersion;
use Waypost\Models\RouteVersionCheckpoint;
use Waypost\Models\Scan;
use Waypost\Models\Schedule;
use Waypost\Models\Shift;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Time;
use Waypost\Support\Uuid;
use Waypost\Tests\Fixtures\ChainBuilder;

use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

/**
 * A stored, ok entry from a signed one.
 *
 * @param  array<string, mixed>  $data
 */
function storedEntry(array $data, string $receivedAt = '2026-10-27T20:00:01.000Z'): ChainEntry
{
    $entry = ChainEntry::fromPush($data);
    $entry->fill(['received_at' => Time::fromIso($receivedAt), 'verify_status' => VerifyStatus::Ok, 'flags' => []])->save();

    return $entry;
}

/** @return array{Device, ChainBuilder, Projector} */
function projecting(): array
{
    $device = Device::factory()->for(Site::factory())->create();

    return [$device, new ChainBuilder($device), new Projector()];
}

/**
 * A route version of the site with its checkpoints in order and walk times; a schedule on the route.
 *
 * @param  list<int|null>  $walkTimes  seconds from the previous one, per checkpoint
 * @return array{RouteVersion, Schedule, list<Checkpoint>}
 */
function routeOf(string $siteId, array $walkTimes): array
{
    $route = Route::factory()->create(['site_id' => $siteId]);
    $version = RouteVersion::factory()->for($route)->create();
    $checkpoints = [];
    foreach ($walkTimes as $index => $walkTime) {
        $checkpoint = Checkpoint::factory()->create(['site_id' => $siteId]);
        RouteVersionCheckpoint::query()->create([
            'route_version_id' => $version->id, 'checkpoint_id' => $checkpoint->id, 'position' => $index + 1, 'walk_time_from_prev_s' => $walkTime,
        ]);
        $checkpoints[] = $checkpoint;
    }

    return [$version, Schedule::factory()->for($route)->create(), $checkpoints];
}

/**
 * A started shift and an opened round, applied.
 *
 * @return array{string, string} the IDs of the shift and the round
 */
function openRound(Device $device, ChainBuilder $chain, Projector $projector, RouteVersion $version, Schedule $schedule): array
{
    $shift = $chain->shiftStarted();
    $round = $chain->roundOpened($shift['payload']['shift_id'], $version->id, $schedule->id);
    $projector->apply($device, storedEntry($shift));
    $projector->apply($device, storedEntry($round));

    return [$shift['payload']['shift_id'], $round['payload']['round_id']];
}

/**
 * The fields of an entry written with a reference of the same boot, at the trusted time 2026-10-27T20:00:00.000Z + $elapsedMs.
 *
 * @return array<string, mixed>
 */
function trustedAt(int $elapsedMs): array
{
    return [
        'device_time' => Time::iso(Time::fromIso('2026-10-27T20:00:00.000Z')->addMilliseconds($elapsedMs)),
        'uptime_ms' => 2_000_000 + $elapsedMs,
        'ref_server_time' => '2026-10-27T20:00:00.000Z',
        'ref_uptime_ms' => 2_000_000,
        'ref_boot_id' => 'boot-1',
    ];
}

it('projects shift_started with the trusted time', function (): void {
    [$device, $chain, $projector] = projecting();
    $guard = User::factory()->create();
    $data = $chain->shiftStarted($guard, [
        'device_time' => '2026-10-27T21:00:00.000Z', 'uptime_ms' => 1_060_000,
        'ref_server_time' => '2026-10-27T20:00:00.000Z', 'ref_uptime_ms' => 1_000_000, 'ref_boot_id' => 'boot-1',
    ]);

    expect($projector->apply($device, storedEntry($data)))->toBeTrue();

    $shift = Shift::query()->sole();
    expect($shift->id)->toBe($data['payload']['shift_id'])
        ->and($shift->site_id)->toBe($device->site_id)
        ->and($shift->device_id)->toBe($device->id)
        ->and($shift->guard_id)->toBe($guard->id)
        ->and(Time::iso($shift->started_at))->toBe('2026-10-27T20:01:00.000Z')
        ->and($shift->ended_at)->toBeNull()
        ->and($shift->chain_entry_id)->toBe($data['id']);
});

it('projects shift_closed with the trusted time and the way it closed', function (): void {
    [$device, $chain, $projector] = projecting();
    $started = $chain->shiftStarted();
    $closed = $chain->shiftClosed($started['payload']['shift_id'], 'handover', ['device_time' => '2026-10-28T06:00:00.000Z']);

    $projector->apply($device, storedEntry($started));
    expect($projector->apply($device, storedEntry($closed)))->toBeTrue();

    $shift = Shift::query()->sole();
    expect(Time::iso($shift->ended_at ?? throw new LogicException()))->toBe('2026-10-28T06:00:00.000Z')
        ->and($shift->closed_by)->toBe(ShiftClosedBy::Handover);
});

it('projects round_opened and round_closed', function (): void {
    [$device, $chain, $projector] = projecting();
    [$version, $schedule] = routeOf((string) $device->site_id, [null]);
    $shift = $chain->shiftStarted();
    $opened = $chain->roundOpened($shift['payload']['shift_id'], $version->id, $schedule->id);
    $projector->apply($device, storedEntry($shift));

    expect($projector->apply($device, storedEntry($opened)))->toBeTrue();

    $round = Round::query()->sole();
    expect($round->id)->toBe($opened['payload']['round_id'])
        ->and($round->status)->toBe(RoundStatus::Open)
        ->and($round->route_version_id)->toBe($version->id)
        ->and($round->schedule_id)->toBe($schedule->id)
        ->and($round->time_limit_min)->toBe(20)
        ->and(Time::iso($round->scheduled_at))->toBe('2026-10-27T20:00:00.000Z')
        ->and($round->started_at)->toBeNull();

    expect($projector->apply($device, storedEntry($chain->roundClosed($round->id, 'incomplete'))))->toBeTrue();

    $round->refresh();
    expect($round->status)->toBe(RoundStatus::Incomplete)
        ->and(Time::iso($round->finished_at ?? throw new LogicException()))->toBe('2026-10-27T20:15:00.000Z');
});

it('projects a scan, whose first checkpoint starts the round', function (): void {
    [$device, $chain, $projector] = projecting();
    [$version, $schedule, [$checkpoint]] = routeOf((string) $device->site_id, [null]);
    [, $roundId] = openRound($device, $chain, $projector, $version, $schedule);
    $data = $chain->scan($roundId, $checkpoint->id, ['lat_e7' => 474979000, 'lng_e7' => 190402000, 'accuracy_dm' => 125, 'note' => 'Rendben.'], trustedAt(5_000));

    expect($projector->apply($device, storedEntry($data)))->toBeTrue();

    $scan = Scan::query()->sole();
    expect($scan->id)->toBe($data['payload']['scan_id'])
        ->and($scan->round_id)->toBe($roundId)
        ->and($scan->checkpoint_id)->toBe($checkpoint->id)
        ->and($scan->method->value)->toBe('nfc')
        ->and(Time::iso($scan->scanned_at))->toBe('2026-10-27T20:00:05.000Z')
        ->and($scan->lat_e7)->toBe(474979000)
        ->and($scan->accuracy_dm)->toBe(125)
        ->and($scan->is_mock)->toBeFalse()
        ->and($scan->note)->toBe('Rendben.')
        ->and($scan->chain_entry_id)->toBe($data['id']);
    $round = Round::query()->sole();
    expect($round->status)->toBe(RoundStatus::InProgress)
        ->and(Time::iso($round->started_at ?? throw new LogicException()))->toBe('2026-10-27T20:00:05.000Z');
});

it('projects a scan outside a round, and of an unknown tag, without starting anything', function (): void {
    [$device, $chain, $projector] = projecting();
    [$version, $schedule] = routeOf((string) $device->site_id, [null]);
    [, $roundId] = openRound($device, $chain, $projector, $version, $schedule);

    $unknown = $chain->scan($roundId, null, ['flags' => ['unknown_tag']]);
    expect($projector->apply($device, storedEntry($unknown)))->toBeTrue();
    $outside = $chain->scan(null, null, ['flags' => ['out_of_round', 'unknown_tag']]);
    expect($projector->apply($device, storedEntry($outside)))->toBeTrue();

    expect(Scan::query()->count())->toBe(2)
        ->and(Scan::query()->whereKey($outside['payload']['scan_id'])->firstOrFail()->flags)->toBe(['out_of_round', 'unknown_tag']);
    $round = Round::query()->sole();
    expect($round->status)->toBe(RoundStatus::Open)->and($round->started_at)->toBeNull();
});

it('projects an incident with the trusted time and its placeholder photos', function (): void {
    [$device, $chain, $projector] = projecting();
    $checkpoint = Checkpoint::factory()->create(['site_id' => $device->site_id]);
    $shift = $chain->shiftStarted();
    $projector->apply($device, storedEntry($shift));
    $photo = ['id' => Uuid::v7(), 'sha256' => str_repeat('a', 64)];
    $data = $chain->incident($shift['payload']['shift_id'], [
        'category' => 'water', 'nearest_checkpoint_id' => $checkpoint->id, 'lat_e7' => 474979000, 'lng_e7' => 190402000, 'accuracy_dm' => 90, 'media' => [$photo],
    ], trustedAt(7_000));

    expect($projector->apply($device, storedEntry($data)))->toBeTrue();

    $incident = Incident::query()->sole();
    expect($incident->id)->toBe($data['payload']['incident_id'])
        ->and($incident->shift_id)->toBe($shift['payload']['shift_id'])
        ->and($incident->category->value)->toBe('water')
        ->and(Time::iso($incident->occurred_at))->toBe('2026-10-27T20:00:07.000Z')
        ->and($incident->nearest_checkpoint_id)->toBe($checkpoint->id)
        ->and($incident->accuracy_dm)->toBe(90)
        ->and($incident->chain_entry_id)->toBe($data['id']);
    $media = Media::query()->sole();
    expect($media->owner_type)->toBe('waypost.incident')
        ->and($media->owner_id)->toBe($incident->id)
        ->and($media->sha256)->toBe($photo['sha256'])
        ->and($media->device_id)->toBe($device->id)
        ->and($media->path)->toBeNull();
});

it('changes nothing when an entry is applied twice', function (): void {
    [$device, $chain, $projector] = projecting();
    [$version, $schedule, [$checkpoint]] = routeOf((string) $device->site_id, [null]);
    $photo = ['id' => Uuid::v7(), 'sha256' => str_repeat('a', 64)];
    $shift = $chain->shiftStarted();
    $shiftId = $shift['payload']['shift_id'];
    $round = $chain->roundOpened($shiftId, $version->id, $schedule->id);
    $roundId = $round['payload']['round_id'];
    $entries = array_map(storedEntry(...), [
        $shift,
        $round,
        $chain->scan($roundId, $checkpoint->id, ['media' => [$photo]], trustedAt(1_000)),
        $chain->incident($shiftId, ['media' => [$photo]], trustedAt(2_000)),
        $chain->roundClosed($roundId),
        $chain->shiftClosed($shiftId, 'guard', trustedAt(3_000)),
    ]);
    $state = function (): array {
        return [
            Shift::query()->get()->toArray(), Round::query()->get()->toArray(), Scan::query()->get()->toArray(),
            Incident::query()->get()->toArray(), Media::query()->get()->toArray(),
            ChainEntry::query()->orderBy('seq')->pluck('flags')->all(),
        ];
    };

    foreach ($entries as $entry) {
        expect($projector->apply($device, $entry))->toBeTrue();
    }
    $once = $state();
    foreach ($entries as $entry) {
        expect($projector->apply($device, $entry->fresh() ?? throw new LogicException()))->toBeTrue();
    }

    expect($state())->toBe($once)
        ->and($once[0])->toHaveCount(1)
        ->and($once[1])->toHaveCount(1)
        ->and($once[2])->toHaveCount(1)
        ->and($once[3])->toHaveCount(1)
        ->and($once[4])->toHaveCount(1);
});

it('keeps the first values of a close applied twice with other data', function (): void {
    [$device, $chain, $projector] = projecting();
    $started = $chain->shiftStarted();
    $projector->apply($device, storedEntry($started));
    $projector->apply($device, storedEntry($chain->shiftClosed($started['payload']['shift_id'], 'guard', ['device_time' => '2026-10-28T06:00:00.000Z'])));
    $projector->apply($device, storedEntry($chain->shiftClosed($started['payload']['shift_id'], 'auto', ['device_time' => '2026-10-28T07:00:00.000Z'])));

    $shift = Shift::query()->sole();
    expect($shift->closed_by)->toBe(ShiftClosedBy::Guard)
        ->and(Time::iso($shift->ended_at ?? throw new LogicException()))->toBe('2026-10-28T06:00:00.000Z');
});

it('leaves an entry for later if it refers to something the server does not know yet', function (string $case): void {
    [$device, $chain, $projector] = projecting();
    $shift = $chain->shiftStarted();
    $shiftId = $shift['payload']['shift_id'];
    [$version, $schedule, [$checkpoint]] = routeOf((string) $device->site_id, [null]);
    $projector->apply($device, storedEntry($shift));
    $unknown = Uuid::v7();

    $data = match ($case) {
        'guard' => $chain->entry('shift_started', ['shift_id' => Uuid::v7(), 'guard_id' => $unknown, 'site_id' => $device->site_id]),
        'site' => $chain->entry('shift_started', ['shift_id' => Uuid::v7(), 'guard_id' => $shift['payload']['guard_id'], 'site_id' => Site::factory()->create()->id]),
        'closed shift' => $chain->shiftClosed($unknown),
        'shift of the round' => $chain->roundOpened($unknown, $version->id, $schedule->id),
        'route version' => $chain->roundOpened($shiftId, $unknown, $schedule->id),
        'schedule' => $chain->roundOpened($shiftId, $version->id, $unknown),
        'closed round' => $chain->roundClosed($unknown),
        'round of the scan' => $chain->scan($unknown, $checkpoint->id),
        'checkpoint' => $chain->scan(null, $unknown),
        'shift of the incident' => $chain->incident($unknown),
        'nearest checkpoint' => $chain->incident($shiftId, ['nearest_checkpoint_id' => $unknown]),
        'scan of the incident' => $chain->incident($shiftId, ['scan_id' => $unknown]),
        default => throw new LogicException($case),
    };

    expect($projector->apply($device, storedEntry($data)))->toBeFalse();
    expect(Shift::query()->count())->toBe(1)
        ->and(Round::query()->count())->toBe(0)
        ->and(Scan::query()->count())->toBe(0)
        ->and(Incident::query()->count())->toBe(0);
})->with([
    'guard', 'site', 'closed shift', 'shift of the round', 'route version', 'schedule',
    'closed round', 'round of the scan', 'checkpoint', 'shift of the incident', 'nearest checkpoint', 'scan of the incident',
]);

it('does not let an entry touch the shift or round of another device', function (): void {
    [$device, $chain, $projector] = projecting();
    [$version, $schedule] = routeOf((string) $device->site_id, [null]);
    [$shiftId, $roundId] = openRound($device, $chain, $projector, $version, $schedule);
    [$otherDevice, $otherChain, ] = projecting();

    expect($projector->apply($otherDevice, storedEntry($otherChain->shiftClosed($shiftId))))->toBeFalse()
        ->and($projector->apply($otherDevice, storedEntry($otherChain->roundClosed($roundId))))->toBeFalse()
        ->and($projector->apply($otherDevice, storedEntry($otherChain->incident($shiftId))))->toBeFalse()
        ->and(Shift::query()->sole()->ended_at)->toBeNull()
        ->and(Round::query()->sole()->status)->toBe(RoundStatus::Open);
});

it('never applies an entry that refers to another site, or to another device\'s scan', function (string $case): void {
    [$device, $chain, $projector] = projecting();
    [$version, $schedule, [$checkpoint]] = routeOf((string) $device->site_id, [null]);
    [$shiftId, $roundId] = openRound($device, $chain, $projector, $version, $schedule);
    $otherSite = Site::factory()->create();
    [$foreignVersion, $foreignSchedule, [$foreignCheckpoint]] = routeOf($otherSite->id, [null]);
    // Another route of the same site: its schedule does not go with the version.
    [, $otherRouteSchedule] = routeOf((string) $device->site_id, [null]);
    // A scan in the chain of the other site's phone.
    $otherDevice = Device::factory()->for($otherSite)->create();
    $otherChain = new ChainBuilder($otherDevice);
    $otherShift = $otherChain->shiftStarted();
    $otherScan = $otherChain->scan(null, $foreignCheckpoint->id);
    expect($projector->apply($otherDevice, storedEntry($otherShift)))->toBeTrue()
        ->and($projector->apply($otherDevice, storedEntry($otherScan)))->toBeTrue();

    $data = match ($case) {
        'route version' => $chain->roundOpened($shiftId, $foreignVersion->id, $schedule->id),
        'schedule' => $chain->roundOpened($shiftId, $foreignVersion->id, $foreignSchedule->id),
        'schedule of another route' => $chain->roundOpened($shiftId, $version->id, $otherRouteSchedule->id),
        'checkpoint of a round' => $chain->scan($roundId, $foreignCheckpoint->id),
        'checkpoint outside a round' => $chain->scan(null, $foreignCheckpoint->id),
        'nearest checkpoint' => $chain->incident($shiftId, ['nearest_checkpoint_id' => $foreignCheckpoint->id]),
        'scan of the incident' => $chain->incident($shiftId, ['scan_id' => $otherScan['payload']['scan_id']]),
        default => throw new LogicException($case),
    };

    expect($projector->apply($device, storedEntry($data)))->toBeFalse();
    expect(Round::query()->count())->toBe(1)
        ->and(Scan::query()->count())->toBe(1)
        ->and(Incident::query()->count())->toBe(0);
})->with([
    'route version', 'schedule', 'schedule of another route', 'checkpoint of a round', 'checkpoint outside a round',
    'nearest checkpoint', 'scan of the incident',
]);

it('takes a checkpoint, a guard, a route and a schedule that were deleted since', function (): void {
    [$device, $chain, $projector] = projecting();
    [$version, $schedule, [$checkpoint]] = routeOf((string) $device->site_id, [null]);
    $guard = User::factory()->create();
    $shift = $chain->shiftStarted($guard);
    $round = $chain->roundOpened($shift['payload']['shift_id'], $version->id, $schedule->id);
    $scan = $chain->scan($round['payload']['round_id'], $checkpoint->id);
    $guard->delete();
    $schedule->delete();
    $version->route()->firstOrFail()->delete();
    $checkpoint->delete();

    expect($projector->apply($device, storedEntry($shift)))->toBeTrue()
        ->and($projector->apply($device, storedEntry($round)))->toBeTrue()
        ->and($projector->apply($device, storedEntry($scan)))->toBeTrue();
});

it('applies a waiting scan later: the round starts with the earliest one, also after it closed', function (): void {
    [$device, $chain, $projector] = projecting();
    [$version, $schedule, [$first, $second]] = routeOf((string) $device->site_id, [null, null]);
    [, $roundId] = openRound($device, $chain, $projector, $version, $schedule);
    $early = storedEntry($chain->scan($roundId, $first->id, [], trustedAt(1_000)));
    $projector->apply($device, storedEntry($chain->scan($roundId, $second->id, [], trustedAt(2_000))));
    $projector->apply($device, storedEntry($chain->roundClosed($roundId)));

    expect($projector->apply($device, $early))->toBeTrue();

    $round = Round::query()->sole();
    expect($round->status)->toBe(RoundStatus::Completed)
        ->and(Time::iso($round->started_at ?? throw new LogicException()))->toBe('2026-10-27T20:00:01.000Z');
});

it('flags too_fast on both sides of 40% of the walk time', function (int $elapsedMs, bool $expected): void {
    [$device, $chain, $projector] = projecting();
    [$version, $schedule, [$first, $second]] = routeOf((string) $device->site_id, [null, 60]);
    [, $roundId] = openRound($device, $chain, $projector, $version, $schedule);
    $projector->apply($device, storedEntry($chain->scan($roundId, $first->id, [], trustedAt(0))));

    $data = $chain->scan($roundId, $second->id, [], trustedAt($elapsedMs));
    $entry = storedEntry($data);
    $projector->apply($device, $entry);

    $flags = $expected ? ['too_fast'] : [];
    expect($entry->fresh()?->flags)->toBe($flags)
        ->and(Scan::query()->whereKey($data['payload']['scan_id'])->firstOrFail()->flags)->toBe($flags);
})->with([
    'just under' => [23_999, true],
    'exactly 40%' => [24_000, false],
]);

it('sets too_fast only for checkpoints next to each other in the training', function (): void {
    [$device, $chain, $projector] = projecting();
    [$version, $schedule, [$first, $second, $third]] = routeOf((string) $device->site_id, [null, 60, 60]);
    [, $roundId] = openRound($device, $chain, $projector, $version, $schedule);
    $projector->apply($device, storedEntry($chain->scan($roundId, $first->id, [], trustedAt(0))));

    // First to third: not neighbours. Third to second: neighbours, but backwards.
    $skipping = storedEntry($chain->scan($roundId, $third->id, ['flags' => ['out_of_order']], trustedAt(1_000)));
    $backwards = storedEntry($chain->scan($roundId, $second->id, [], trustedAt(2_000)));
    $projector->apply($device, $skipping);
    $projector->apply($device, $backwards);

    expect($skipping->fresh()?->flags)->toBe([])->and($backwards->fresh()?->flags)->toBe([]);
});

it('sets no too_fast on the first scan, or without a trained time', function (): void {
    [$device, $chain, $projector] = projecting();
    [$version, $schedule, [$first, $second]] = routeOf((string) $device->site_id, [null, null]);
    [, $roundId] = openRound($device, $chain, $projector, $version, $schedule);
    $start = storedEntry($chain->scan($roundId, $first->id, [], trustedAt(0)));
    $next = storedEntry($chain->scan($roundId, $second->id, [], trustedAt(1)));

    $projector->apply($device, $start);
    $projector->apply($device, $next);

    expect($start->fresh()?->flags)->toBe([])->and($next->fresh()?->flags)->toBe([]);
});

it('compares too_fast with the previous checkpoint scan, skipping an unknown tag between them', function (): void {
    [$device, $chain, $projector] = projecting();
    [$version, $schedule, [$first, $second]] = routeOf((string) $device->site_id, [null, 60]);
    [, $roundId] = openRound($device, $chain, $projector, $version, $schedule);
    $projector->apply($device, storedEntry($chain->scan($roundId, $first->id, [], trustedAt(0))));
    $projector->apply($device, storedEntry($chain->scan($roundId, null, ['flags' => ['unknown_tag']], trustedAt(500))));

    $next = storedEntry($chain->scan($roundId, $second->id, [], trustedAt(1_000)));
    $projector->apply($device, $next);

    expect($next->fresh()?->flags)->toBe(['too_fast']);
});

it('flags far_from_checkpoint on a scan, next to the flags of the app', function (): void {
    [$device, $chain, $projector] = projecting();
    $checkpoint = Checkpoint::factory()->create(['site_id' => $device->site_id, 'lat_e7' => 474979000, 'lng_e7' => 190402000, 'accuracy_dm' => 100]);

    $far = $chain->scan(null, $checkpoint->id, ['lat_e7' => 474979000 + 4497, 'lng_e7' => 190402000, 'accuracy_dm' => 100]);
    $near = $chain->scan(null, $checkpoint->id, ['lat_e7' => 474979000 + 4496, 'lng_e7' => 190402000, 'accuracy_dm' => 100]);
    $projector->apply($device, storedEntry($far));
    $projector->apply($device, storedEntry($near));

    expect(ChainEntry::query()->whereKey($far['id'])->firstOrFail()->flags)->toBe(['far_from_checkpoint'])
        ->and(Scan::query()->whereKey($far['payload']['scan_id'])->firstOrFail()->flags)->toBe(['out_of_round', 'far_from_checkpoint'])
        ->and(ChainEntry::query()->whereKey($near['id'])->firstOrFail()->flags)->toBe([])
        ->and(Scan::query()->whereKey($near['payload']['scan_id'])->firstOrFail()->flags)->toBe(['out_of_round']);
});

it('flags late_sync on the entries of a shift that arrived more than 12 hours after it closed', function (string $receivedAt, bool $expected): void {
    [$device, $chain, $projector] = projecting();
    [$version, $schedule, [$checkpoint]] = routeOf((string) $device->site_id, [null]);
    $before = storedEntry($chain->shiftStarted(fields: ['device_time' => '2026-10-27T19:00:00.000Z']), '2026-10-27T19:00:01.000Z');
    $projector->apply($device, $before);
    $projector->apply($device, storedEntry($chain->shiftClosed($before->payload['shift_id'], 'guard', ['device_time' => '2026-10-27T19:30:00.000Z']), '2026-10-27T19:30:01.000Z'));

    $shift = $chain->shiftStarted(fields: ['device_time' => '2026-10-27T20:00:00.000Z']);
    $shiftId = $shift['payload']['shift_id'];
    $round = $chain->roundOpened($shiftId, $version->id, $schedule->id);
    $scan = $chain->scan($round['payload']['round_id'], $checkpoint->id);
    $outside = $chain->scan(null, $checkpoint->id);
    $incident = $chain->incident($shiftId);
    $closing = $chain->shiftClosed($shiftId, 'auto', ['device_time' => '2026-10-28T06:00:00.000Z']);
    $inShift = [$shift, $round, $scan, $outside, $incident, $closing];

    foreach ($inShift as $data) {
        $projector->apply($device, storedEntry($data, $receivedAt));
    }

    $flags = $expected ? ['late_sync'] : [];
    foreach ($inShift as $data) {
        expect(ChainEntry::query()->whereKey($data['id'])->firstOrFail()->flags)->toBe($flags);
    }
    expect(Scan::query()->whereKey($scan['payload']['scan_id'])->firstOrFail()->flags)->toBe($flags)
        ->and(Scan::query()->whereKey($outside['payload']['scan_id'])->firstOrFail()->flags)->toBe(['out_of_round', ...$flags])
        // The shift before it arrived in time.
        ->and($before->fresh()?->flags)->toBe([]);
})->with([
    'exactly 12 hours' => ['2026-10-28T18:00:00.000Z', false],
    '1 ms over' => ['2026-10-28T18:00:00.001Z', true],
]);

it('flags only the entries of a shift that really arrived late', function (): void {
    [$device, $chain, $projector] = projecting();
    $started = $chain->shiftStarted();
    $startedEntry = storedEntry($started, '2026-10-27T20:00:01.000Z');
    $projector->apply($device, $startedEntry);
    $closing = storedEntry($chain->shiftClosed($started['payload']['shift_id'], 'guard', ['device_time' => '2026-10-28T06:00:00.000Z']), '2026-10-28T20:00:00.000Z');

    $projector->apply($device, $closing);

    expect($startedEntry->fresh()?->flags)->toBe([])->and($closing->fresh()?->flags)->toBe(['late_sync']);
});

it('runs the whole chain through a push, also what waited for an outbox request', function (): void {
    travelTo('2026-10-27T21:00:00.000Z');
    $site = Site::factory()->create();
    $device = Device::factory()->for($site)->create();
    $chain = new ChainBuilder($device);
    $shift = $chain->shiftStarted();
    $checkpointId = Uuid::v7();
    $photo = ['id' => Uuid::v7(), 'sha256' => str_repeat('a', 64)];
    $scan = $chain->scan(null, $checkpointId, ['media' => [$photo]]);
    $incident = $chain->incident($shift['payload']['shift_id']);

    // Trained offline: the checkpoint comes with the outbox, after the push.
    $log = packageLog();
    $response = pushAs($device, [$shift, $scan, $incident])->assertOk();
    $log->shouldHaveReceived('info')->once()->with('Waypost: accepted chain entries wait for what they refer to.', ['device_id' => $device->id, 'site_id' => $site->id, 'waiting' => 1]);

    expect($response->json())->toMatchArray(['accepted' => [$shift['id'], $scan['id'], $incident['id']], 'last_seq' => 3, 'rejected' => [], 'media_missing' => [$photo['id']]]);
    expect(Scan::query()->count())->toBe(0)
        ->and(Incident::query()->count())->toBe(1)
        ->and(ChainEntry::query()->whereKey($scan['id'])->firstOrFail()->verify_status)->toBe(VerifyStatus::Ok)
        ->and(ChainEntry::query()->whereKey($scan['id'])->firstOrFail()->projected_at)->toBeNull();

    Checkpoint::factory()->for($site)->create(['id' => $checkpointId]);
    pushAs($device, [$chain->incident($shift['payload']['shift_id'])])->assertOk();

    expect(Scan::query()->sole()->id)->toBe($scan['payload']['scan_id'])
        ->and(ChainEntry::query()->whereKey($scan['id'])->firstOrFail()->projected_at)->not->toBeNull();
});

it('flags time_suspect at acceptance, on every type of entry', function (): void {
    $device = Device::factory()->for(Site::factory())->create();
    $chain = new ChainBuilder($device);
    $shift = $chain->shiftStarted(fields: ['gnss_time' => '2026-10-27T20:02:00.001Z']);
    $incident = $chain->incident($shift['payload']['shift_id'], fields: ['gnss_time' => '2026-10-27T20:02:00.000Z']);
    $scan = $chain->scan(null, null, ['flags' => ['out_of_round', 'unknown_tag']], ['gnss_time' => '2026-10-27T19:50:00.000Z']);

    pushAs($device, [$shift, $incident, $scan])->assertOk();

    expect(ChainEntry::query()->whereKey($shift['id'])->firstOrFail()->flags)->toBe(['time_suspect'])
        ->and(ChainEntry::query()->whereKey($incident['id'])->firstOrFail()->flags)->toBe([])
        ->and(Scan::query()->whereKey($scan['payload']['scan_id'])->firstOrFail()->flags)->toBe(['out_of_round', 'unknown_tag', 'time_suspect']);
});
