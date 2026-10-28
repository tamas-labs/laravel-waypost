<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Waypost\Support\Time;

use function Pest\Laravel\travelTo;

it('uses the pattern of the shared schema', function (): void {
    expect(Time::ISO_PATTERN)->toBe(schemaPattern('common/timestamp'));
});

it('formats an instant in UTC with milliseconds', function (): void {
    $time = CarbonImmutable::parse('2026-10-27 20:00:00.123456', 'UTC');

    expect(Time::iso($time))->toBe('2026-10-27T20:00:00.123Z')
        ->and(Time::iso($time))->toMatch(Time::ISO_PATTERN);
});

it('always prints three fractional digits', function (): void {
    expect(Time::iso(CarbonImmutable::parse('2026-10-27 20:00:00', 'UTC')))->toBe('2026-10-27T20:00:00.000Z')
        ->and(Time::iso(CarbonImmutable::parse('2026-10-27 20:00:00.5', 'UTC')))->toBe('2026-10-27T20:00:00.500Z');
});

it('truncates sub-millisecond digits', function (): void {
    expect(Time::iso(CarbonImmutable::parse('2026-10-27 20:00:00.999999', 'UTC')))->toBe('2026-10-27T20:00:00.999Z');
});

it('converts other time zones to UTC', function (): void {
    // Summer time (UTC+2) and winter time (UTC+1) in Budapest, either side of the 2026-10-25 change.
    expect(Time::iso(Carbon::parse('2026-10-24 22:00:00.250', 'Europe/Budapest')))->toBe('2026-10-24T20:00:00.250Z')
        ->and(Time::iso(Carbon::parse('2026-10-27 21:00:00', 'Europe/Budapest')))->toBe('2026-10-27T20:00:00.000Z')
        ->and(Time::iso(new DateTimeImmutable('2026-10-27T15:00:00.001-05:00')))->toBe('2026-10-27T20:00:00.001Z');
});

it('does not change the time zone of the given instance', function (): void {
    $time = Carbon::parse('2026-10-27 21:00:00', 'Europe/Budapest');
    Time::iso($time);

    expect($time->getTimezone()->getName())->toBe('Europe/Budapest');
});

it('gives the current time in UTC', function (): void {
    config(['app.timezone' => 'Europe/Budapest']);
    date_default_timezone_set('Europe/Budapest');
    travelTo(CarbonImmutable::parse('2026-10-27 20:00:00.123', 'UTC'));

    expect(Time::now()->getTimezone()->getName())->toBe('UTC')
        ->and(Time::iso(Time::now()))->toBe('2026-10-27T20:00:00.123Z');
})->after(fn () => date_default_timezone_set('UTC'));

it('parses the API form', function (): void {
    $time = Time::fromIso('2026-10-27T20:00:00.123Z');

    expect($time->getTimezone()->getName())->toBe('UTC')
        ->and($time->format('Y-m-d H:i:s.u'))->toBe('2026-10-27 20:00:00.123000')
        ->and(Time::iso($time))->toBe('2026-10-27T20:00:00.123Z');
});

it('rejects other text forms', function (string $value): void {
    expect(fn () => Time::fromIso($value))->toThrow(InvalidArgumentException::class);
})->with([
    'no milliseconds' => '2026-10-27T20:00:00Z',
    'microseconds' => '2026-10-27T20:00:00.123456Z',
    'offset' => '2026-10-27T21:00:00.000+01:00',
    'lowercase z' => '2026-10-27T20:00:00.000z',
    'space separator' => '2026-10-27 20:00:00.000Z',
    'impossible date' => '2026-02-30T20:00:00.000Z',
    'leap second' => '2026-12-31T23:59:60.000Z',
    'trailing newline' => "2026-10-27T20:00:00.000Z\n",
    'empty' => '',
]);
