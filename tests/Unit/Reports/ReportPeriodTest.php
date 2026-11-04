<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Waypost\Enums\ReportFrequency;
use Waypost\Reports\ReportPeriod;
use Waypost\Support\Time;

/** @return array{string, string, string}|null from, to, send time of the due period */
function duePeriod(ReportFrequency $type, string $timezone, string $now): ?array
{
    $period = ReportPeriod::due($type, $timezone, CarbonImmutable::parse($now));

    return $period === null ? null : [$period->fromDate, $period->toDate, Time::iso($period->sendAt)];
}

it('is due at 10:00 on the site clock, for the day before, and made up for 12 hours', function (string $timezone, string $now, ?array $expected): void {
    expect(duePeriod(ReportFrequency::Daily, $timezone, $now))->toBe($expected);
})->with([
    'Budapest, 09:59 CET' => ['Europe/Budapest', '2026-11-04T08:59:59Z', null],
    'Budapest, 10:00 CET' => ['Europe/Budapest', '2026-11-04T09:00:00Z', ['2026-11-03', '2026-11-03', '2026-11-04T09:00:00.000Z']],
    'Budapest, 21:59 CET' => ['Europe/Budapest', '2026-11-04T20:59:59Z', ['2026-11-03', '2026-11-03', '2026-11-04T09:00:00.000Z']],
    'Budapest, 22:00 CET' => ['Europe/Budapest', '2026-11-04T21:00:00Z', null],
    'Budapest, the spring change: 10:00 CEST' => ['Europe/Budapest', '2026-03-29T08:00:00Z', ['2026-03-28', '2026-03-28', '2026-03-29T08:00:00.000Z']],
    'New York, 10:00 EST' => ['America/New_York', '2026-11-04T15:00:00Z', ['2026-11-03', '2026-11-03', '2026-11-04T15:00:00.000Z']],
    'New York, 09:00 EST (14:00 UTC)' => ['America/New_York', '2026-11-04T14:00:00Z', null],
    'Auckland, 10:00 NZDT the day before in UTC' => ['Pacific/Auckland', '2026-11-03T21:00:00Z', ['2026-11-03', '2026-11-03', '2026-11-03T21:00:00.000Z']],
]);

it('is due on Monday for the Monday to Sunday before', function (): void {
    expect(duePeriod(ReportFrequency::Weekly, 'Europe/Budapest', '2026-11-09T08:59:00Z'))->toBeNull()
        ->and(duePeriod(ReportFrequency::Weekly, 'Europe/Budapest', '2026-11-09T09:00:00Z'))->toBe(['2026-11-02', '2026-11-08', '2026-11-09T09:00:00.000Z'])
        ->and(duePeriod(ReportFrequency::Weekly, 'Europe/Budapest', '2026-11-04T09:00:00Z'))->toBeNull()
        ->and(ReportPeriod::latest(ReportFrequency::Weekly, 'Europe/Budapest', CarbonImmutable::parse('2026-11-04T09:00:00Z'))->fromDate)->toBe('2026-10-26');
});

it('is due on the 1st for the calendar month before', function (): void {
    expect(duePeriod(ReportFrequency::Monthly, 'Europe/Budapest', '2026-11-01T09:00:00Z'))->toBe(['2026-10-01', '2026-10-31', '2026-11-01T09:00:00.000Z'])
        ->and(duePeriod(ReportFrequency::Monthly, 'Europe/Budapest', '2026-12-01T09:30:00Z'))->toBe(['2026-11-01', '2026-11-30', '2026-12-01T09:00:00.000Z'])
        ->and(duePeriod(ReportFrequency::Monthly, 'Europe/Budapest', '2026-03-01T09:00:00Z'))->toBe(['2026-02-01', '2026-02-28', '2026-03-01T09:00:00.000Z'])
        ->and(duePeriod(ReportFrequency::Monthly, 'Europe/Budapest', '2026-11-02T09:00:00Z'))->toBeNull();
});
