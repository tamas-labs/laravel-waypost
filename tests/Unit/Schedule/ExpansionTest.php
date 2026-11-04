<?php

declare(strict_types=1);

use Waypost\Models\Schedule;
use Waypost\Schedule\ExpectedRound;
use Waypost\Schedule\Expansion;
use Waypost\Schema\Paths;
use Waypost\Support\Time;

/**
 * @return list<array{name: string, schedule: array<string, mixed>, timezone: string, from_date: string, to_date: string,
 *     expected: list<array{start_day: string, wall_time: string, local: string, utc: string}>}>
 */
function expansionVectors(): array
{
    /** @var array{expansion: list<array{name: string, schedule: array<string, mixed>, timezone: string, from_date: string, to_date: string, expected: list<array{start_day: string, wall_time: string, local: string, utc: string}>}>} $vectors */
    $vectors = json_decode((string) file_get_contents(Paths::scheduleVectors()), true, flags: JSON_THROW_ON_ERROR);

    return $vectors['expansion'];
}

/** @param  array<string, mixed>  $attributes  entities/schedule.json */
function scheduleOf(array $attributes): Schedule
{
    unset($attributes['created_at'], $attributes['updated_at']);

    return new Schedule($attributes);
}

/** @return array{start_day: string, wall_time: string, local: string, utc: string} */
function vectorShape(ExpectedRound $round): array
{
    return [
        'start_day' => $round->startDay,
        'wall_time' => $round->wallTime,
        'local' => $round->local->format('Y-m-d\TH:i:sP'),
        'utc' => Time::iso($round->utc),
    ];
}

it('expands every schedule of schedule/test-vectors.json', function (): void {
    $vectors = expansionVectors();
    expect($vectors)->not->toBeEmpty();

    foreach ($vectors as $vector) {
        $rounds = Expansion::expand(scheduleOf($vector['schedule']), $vector['timezone'], $vector['from_date'], $vector['to_date']);

        expect(array_map(vectorShape(...), $rounds))->toBe($vector['expected'], $vector['name']);
    }
});

it('resolves the two DST cases against PHP\'s own choice', function (): void {
    $zone = new DateTimeZone('Europe/Budapest');
    $wallClock = fn (string $time): int => (int) strtotime("{$time} UTC");

    // Autumn: 02:30 occurs twice; the first occurrence (CEST) is 00:30 UTC, PHP would say 01:30.
    expect(Expansion::resolve($wallClock('2026-10-25 02:30'), $zone))->toBe($wallClock('2026-10-25 00:30'))
        ->and((new DateTimeImmutable('2026-10-25 02:30', $zone))->getTimestamp())->toBe($wallClock('2026-10-25 01:30'));
    // Spring: 02:30 does not exist; the change itself (03:00 CEST) is 01:00 UTC, PHP would say 03:30 CEST.
    expect(Expansion::resolve($wallClock('2026-03-29 02:30'), $zone))->toBe($wallClock('2026-03-29 01:00'))
        ->and((new DateTimeImmutable('2026-03-29 02:30', $zone))->getTimestamp())->toBe($wallClock('2026-03-29 01:30'));
    // An ordinary time.
    expect(Expansion::resolve($wallClock('2026-11-03 20:00'), $zone))->toBe($wallClock('2026-11-03 19:00'));
});

it('steps an interval across midnight and up to its end', function (): void {
    $schedule = scheduleOf(['mode' => 'interval', 'interval_from' => '22:00', 'interval_to' => '01:30', 'interval_every_min' => 45, 'weekdays' => 127]);

    expect(Expansion::windowMinutes($schedule))->toBe([1320, 1365, 1410, 1455, 1500]);
});

it('rejects an incomplete interval and a date that does not exist', function (): void {
    $incomplete = scheduleOf(['id' => 'x', 'mode' => 'interval', 'interval_from' => '20:00', 'interval_to' => null, 'interval_every_min' => 60, 'weekdays' => 127]);
    $list = scheduleOf(['mode' => 'list', 'start_times' => ['20:00'], 'weekdays' => 127]);

    expect(fn () => Expansion::windowMinutes($incomplete))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Expansion::expand($list, 'Europe/Budapest', '2026-02-30', '2026-03-01'))->toThrow(InvalidArgumentException::class);
});
