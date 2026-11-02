<?php

declare(strict_types=1);

use Waypost\Chain\TrustedTime;
use Waypost\Models\ChainEntry;
use Waypost\Support\Time;

/** @param  array<string, mixed>  $fields */
function clockEntry(array $fields = []): ChainEntry
{
    return new ChainEntry([
        'device_time' => Time::fromIso('2026-10-27T21:03:44.120Z'),
        'uptime_ms' => 86_400_123,
        'boot_id' => 'boot-1',
        ...$fields,
    ]);
}

/** @return array<string, mixed> */
function syncReference(string $bootId = 'boot-1', int $uptimeMs = 82_500_000): array
{
    return ['ref_server_time' => Time::fromIso('2026-10-27T19:58:02.511Z'), 'ref_uptime_ms' => $uptimeMs, 'ref_boot_id' => $bootId];
}

it('is the server time plus the monotonic time elapsed, with a reference of the same boot', function (): void {
    // 19:58:02.511 + 3 900 123 ms
    expect(Time::iso(TrustedTime::of(clockEntry(syncReference()))))->toBe('2026-10-27T21:03:02.634Z');
});

it('is the wall clock without a reference, after a reboot, or if the uptime went backwards', function (array $fields): void {
    expect(Time::iso(TrustedTime::of(clockEntry($fields))))->toBe('2026-10-27T21:03:44.120Z');
})->with([
    'no reference' => [[]],
    'other boot' => [syncReference('boot-0')],
    'uptime before the reference' => [syncReference('boot-1', 90_000_000)],
]);

it('counts from the reference at its very uptime', function (): void {
    expect(Time::iso(TrustedTime::of(clockEntry([...syncReference('boot-1', 86_400_123)]))))->toBe('2026-10-27T19:58:02.511Z');
});
