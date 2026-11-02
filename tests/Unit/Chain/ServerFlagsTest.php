<?php

declare(strict_types=1);

use Waypost\Chain\ServerFlags;
use Waypost\Models\ChainEntry;
use Waypost\Models\Checkpoint;
use Waypost\Support\Time;

/** @param  array<string, mixed>  $fields */
function flaggedEntry(array $fields): ChainEntry
{
    return new ChainEntry(['device_time' => Time::fromIso('2026-10-27T20:00:00.000Z'), 'uptime_ms' => 1_000_000, 'boot_id' => 'boot-1', ...$fields]);
}

it('sets time_suspect above 2 minutes between the estimate of the same boot and the device time', function (string $serverTime, bool $expected): void {
    $entry = flaggedEntry(['ref_server_time' => Time::fromIso($serverTime), 'ref_uptime_ms' => 1_000_000, 'ref_boot_id' => 'boot-1']);

    expect(ServerFlags::timeSuspect($entry))->toBe($expected);
})->with([
    'exactly 2 minutes behind' => ['2026-10-27T19:58:00.000Z', false],
    '1 ms over, behind' => ['2026-10-27T19:57:59.999Z', true],
    'exactly 2 minutes ahead' => ['2026-10-27T20:02:00.000Z', false],
    '1 ms over, ahead' => ['2026-10-27T20:02:00.001Z', true],
]);

it('sets no time_suspect from a reference of another boot, or without a reference', function (): void {
    $otherBoot = flaggedEntry(['ref_server_time' => Time::fromIso('2026-10-20T20:00:00.000Z'), 'ref_uptime_ms' => 5, 'ref_boot_id' => 'boot-0']);

    expect(ServerFlags::timeSuspect($otherBoot))->toBeFalse()
        ->and(ServerFlags::timeSuspect(flaggedEntry([])))->toBeFalse();
});

it('sets time_suspect above 2 minutes between the GNSS time and the device time', function (string $gnssTime, bool $expected): void {
    expect(ServerFlags::timeSuspect(flaggedEntry(['gnss_time' => Time::fromIso($gnssTime)])))->toBe($expected);
})->with([
    'exactly 2 minutes' => ['2026-10-27T20:02:00.000Z', false],
    '1 ms over' => ['2026-10-27T20:02:00.001Z', true],
    'exactly 2 minutes before' => ['2026-10-27T19:58:00.000Z', false],
    '1 ms over before' => ['2026-10-27T19:57:59.999Z', true],
]);

it('sets too_fast below 40% of the trained walk time', function (string $current, ?int $walkSeconds, bool $expected): void {
    expect(ServerFlags::tooFast(Time::fromIso('2026-10-27T20:00:00.000Z'), Time::fromIso($current), $walkSeconds))->toBe($expected);
})->with([
    '1 ms under 40% of 60 s' => ['2026-10-27T20:00:23.999Z', 60, true],
    'exactly 40% of 60 s' => ['2026-10-27T20:00:24.000Z', 60, false],
    'no trained time' => ['2026-10-27T20:00:01.000Z', null, false],
]);

it('sets far_from_checkpoint above 50 m', function (int $latE7, bool $expected): void {
    $checkpoint = new Checkpoint(['lat_e7' => 474979000, 'lng_e7' => 190402000, 'accuracy_dm' => 100]);

    // 10^-7 degree of latitude is 11.12 mm: 4496 is 49.99 m, 4497 is 50.00(4) m.
    expect(ServerFlags::farFromCheckpoint($latE7, 190402000, 100, $checkpoint))->toBe($expected);
})->with([
    'just inside' => [474979000 + 4496, false],
    'just outside' => [474979000 + 4497, true],
]);

it('allows 3 × the accuracy of the scan if that is more than 50 m', function (int $latE7, bool $expected): void {
    $checkpoint = new Checkpoint(['lat_e7' => 474979000, 'lng_e7' => 190402000, 'accuracy_dm' => 100]);

    // Accuracy 30 m: 90 m is allowed; 8093 is 89.99 m, 8095 is 90.01 m.
    expect(ServerFlags::farFromCheckpoint($latE7, 190402000, 300, $checkpoint))->toBe($expected);
})->with([
    'just inside' => [474979000 + 8093, false],
    'just outside' => [474979000 + 8095, true],
]);

it('sets far_from_checkpoint only if both accuracies are below 50 m', function (int $scanDm, int $checkpointDm, bool $expected): void {
    $checkpoint = new Checkpoint(['lat_e7' => 474979000, 'lng_e7' => 190402000, 'accuracy_dm' => $checkpointDm]);

    // About 1 km away, far over any limit here.
    expect(ServerFlags::farFromCheckpoint(475069000, 190402000, $scanDm, $checkpoint))->toBe($expected);
})->with([
    'both 1 dm below' => [499, 499, true],
    'scan exactly 50 m' => [500, 100, false],
    'checkpoint exactly 50 m' => [100, 500, false],
]);

it('sets no far_from_checkpoint without a location or an accuracy', function (): void {
    $full = new Checkpoint(['lat_e7' => 474979000, 'lng_e7' => 190402000, 'accuracy_dm' => 100]);

    expect(ServerFlags::farFromCheckpoint(null, null, null, $full))->toBeFalse()
        ->and(ServerFlags::farFromCheckpoint(475069000, 190402000, null, $full))->toBeFalse()
        ->and(ServerFlags::farFromCheckpoint(475069000, 190402000, 100, new Checkpoint(['lat_e7' => null, 'lng_e7' => null, 'accuracy_dm' => null])))->toBeFalse()
        ->and(ServerFlags::farFromCheckpoint(475069000, 190402000, 100, new Checkpoint(['lat_e7' => 474979000, 'lng_e7' => 190402000, 'accuracy_dm' => null])))->toBeFalse();
});

it('measures the distance on a sphere, also across the antimeridian', function (): void {
    // One degree of latitude.
    expect(ServerFlags::distanceM(470000000, 190000000, 480000000, 190000000))->toEqualWithDelta(111_195, 1)
        // One degree of longitude at the equator, across ±180°.
        ->and(ServerFlags::distanceM(0, 1795000000, 0, -1795000000))->toEqualWithDelta(111_195, 1)
        ->and(ServerFlags::distanceM(474979000, 190402000, 474979000, 190402000))->toBe(0.0);
});

it('sets late_sync more than 12 hours after the close of the shift', function (string $receivedAt, bool $expected): void {
    expect(ServerFlags::lateSync(Time::fromIso($receivedAt), Time::fromIso('2026-10-28T06:00:00.000Z')))->toBe($expected);
})->with([
    'exactly 12 hours' => ['2026-10-28T18:00:00.000Z', false],
    '1 ms over' => ['2026-10-28T18:00:00.001Z', true],
    'before the close' => ['2026-10-28T05:00:00.000Z', false],
]);
