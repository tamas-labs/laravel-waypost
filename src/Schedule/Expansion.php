<?php

declare(strict_types=1);

namespace Waypost\Schedule;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Waypost\Enums\ScheduleMode;
use Waypost\Models\Schedule;

/**
 * Schedule → expected round times (spec: Állapotgépek / Menetrend → körök), the same rules as the app's
 * src/domain/schedule.ts; waypost-schema schedule/test-vectors.json checks both (its docs/decisions.md has them):
 * - the times are wall-clock times of the site's time zone; an interval steps in wall-clock minutes;
 * - a list is a sequence from the start day: an element not later than the previous one is on the next day;
 *   an interval whose end is not later than its start crosses midnight;
 * - weekdays filters the start day, and every round of a window belongs to its start day;
 * - a wall-clock time that occurs twice (autumn) is its first occurrence, one that does not exist (spring) the
 *   first valid instant after the change (PHP's own choice is the other one in both cases); two times of a window
 *   on the same instant are one round.
 */
final class Expansion
{
    private const int MINUTES_PER_DAY = 1440;

    private const int SECONDS_PER_DAY = 86400;

    private function __construct()
    {
    }

    /**
     * Every round of the windows that start on $fromDate … $toDate (local dates of the site, Y-m-d, inclusive), in
     * time order.
     *
     * @return list<ExpectedRound>
     */
    public static function expand(Schedule $schedule, string $timezone, string $fromDate, string $toDate): array
    {
        $zone = new DateTimeZone($timezone);
        $minutesOfWindow = self::windowMinutes($schedule);
        $rounds = [];
        for ($day = self::date($fromDate), $last = self::date($toDate); $day <= $last; $day = $day->addDay()) {
            // Monday = 1 … Sunday = 64.
            if (($schedule->weekdays & (1 << ($day->dayOfWeekIso - 1))) === 0) {
                continue;
            }
            $seen = [];
            foreach ($minutesOfWindow as $minutes) {
                $utc = self::resolve($day->getTimestamp() + $minutes * 60, $zone);
                // Two wall-clock times moved onto the same instant (spring gap) are one round.
                if (isset($seen[$utc])) {
                    continue;
                }
                $seen[$utc] = true;
                $instant = CarbonImmutable::createFromTimestampUTC($utc);
                $rounds[] = new ExpectedRound(
                    startDay: $day->format('Y-m-d'),
                    wallTime: sprintf('%02d:%02d', intdiv($minutes % self::MINUTES_PER_DAY, 60), $minutes % 60),
                    utc: $instant,
                    local: $instant->setTimezone($zone),
                );
            }
        }

        return $rounds;
    }

    /**
     * The wall-clock minutes of one window from the start day's midnight (beyond 1440 on the next day), in order.
     *
     * @return list<int>
     */
    public static function windowMinutes(Schedule $schedule): array
    {
        if ($schedule->mode === ScheduleMode::List) {
            $minutes = [];
            $dayOffset = 0;
            $previous = null;
            foreach ($schedule->start_times ?? [] as $time) {
                $minute = self::minutes($time);
                // A list is a sequence: an element not later than the previous one belongs to the next day.
                if ($previous !== null && $minute <= $previous) {
                    $dayOffset += self::MINUTES_PER_DAY;
                }
                $minutes[] = $dayOffset + $minute;
                $previous = $minute;
            }

            return $minutes;
        }

        if ($schedule->interval_from === null || $schedule->interval_to === null || $schedule->interval_every_min === null || $schedule->interval_every_min < 1) {
            throw new InvalidArgumentException("The interval schedule {$schedule->id} is incomplete.");
        }
        $from = self::minutes($schedule->interval_from);
        $to = self::minutes($schedule->interval_to);
        if ($to <= $from) {
            $to += self::MINUTES_PER_DAY;
        }

        // The server refuses interval_to = interval_from (a window of 24 h); if one is stored anyway (an import, a manual
        // edit), its end is the next day's start, which belongs to that day's window only: as the app counts it (FR-019).
        $last = $to - $from === self::MINUTES_PER_DAY ? $to - 1 : $to;

        // Not range(): it throws if the step is longer than the window (e.g. every 1440 minutes).
        $minutes = [];
        for ($minute = $from; $minute <= $last; $minute += $schedule->interval_every_min) {
            $minutes[] = $minute;
        }

        return $minutes;
    }

    /**
     * The UTC instant (Unix seconds) of a local wall-clock time, given as the seconds it would be in UTC: of a time
     * that occurs twice the first, of one that does not exist the change itself (the first valid instant after it).
     */
    public static function resolve(int $wallClock, DateTimeZone $zone): int
    {
        // The offsets around the time: a day apart, a time zone changes at most once in between.
        $before = self::offsetAt($wallClock - self::SECONDS_PER_DAY, $zone);
        $after = self::offsetAt($wallClock + self::SECONDS_PER_DAY, $zone);
        $candidates = array_filter(
            array_unique([$wallClock - $before, $wallClock - $after]),
            fn (int $utc): bool => self::offsetAt($utc, $zone) + $utc === $wallClock,
        );
        if ($candidates !== []) {
            return min($candidates);
        }

        // In the gap of a change: the change lies between the two candidates. Searched by the offset, not with
        // getTransitions(), which misses the changes a system time zone database (slim TZif, e.g. PHP on Ubuntu)
        // only states as a rule.
        $low = $wallClock - max($before, $after);
        $high = $wallClock - min($before, $after);
        $offset = self::offsetAt($low, $zone);
        if (self::offsetAt($high, $zone) !== $offset) {
            while ($high - $low > 1) {
                $middle = intdiv($low + $high, 2);
                if (self::offsetAt($middle, $zone) === $offset) {
                    $low = $middle;
                } else {
                    $high = $middle;
                }
            }

            return $high;
        }

        throw new InvalidArgumentException("No instant for the wall-clock time {$wallClock} in {$zone->getName()}.");
    }

    private static function offsetAt(int $utc, DateTimeZone $zone): int
    {
        return $zone->getOffset(new DateTimeImmutable("@{$utc}"));
    }

    /** Midnight of a local date, as a naive (UTC) date. */
    private static function date(string $date): CarbonImmutable
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        if (!$day instanceof CarbonImmutable || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Not a date: {$date}");
        }

        return $day;
    }

    private static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
