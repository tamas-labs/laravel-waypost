<?php

declare(strict_types=1);

namespace Waypost\Reports;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Waypost\Enums\ReportFrequency;
use Waypost\Schedule\Expansion;

/**
 * The period of a daily, weekly or monthly report and when it is sent (spec: Jelentések): at 10:00 on the site's
 * clock, every day for the day before, on Monday for the Monday–Sunday before, on the 1st for the month before.
 * A report not sent at its time (the scheduler did not run) is sent later, but at most CATCH_UP_HOURS after it.
 */
final readonly class ReportPeriod
{
    public const int SEND_HOUR = 10;

    public const int CATCH_UP_HOURS = 12;

    private function __construct(
        public ReportFrequency $type,
        /** First and last local day (Y-m-d), inclusive. */
        public string $fromDate,
        public string $toDate,
        /** 10:00 local after the period, in UTC. */
        public CarbonImmutable $sendAt,
    ) {
    }

    /** The latest period of the type whose send time has passed, if that was at most CATCH_UP_HOURS ago. */
    public static function due(ReportFrequency $type, string $timezone, CarbonImmutable $now): ?self
    {
        $period = self::latest($type, $timezone, $now);

        return $now->lessThan($period->sendAt->addHours(self::CATCH_UP_HOURS)) ? $period : null;
    }

    /** The latest period of the type whose send time is not after now. */
    public static function latest(ReportFrequency $type, string $timezone, CarbonImmutable $now): self
    {
        $zone = new DateTimeZone($timezone);
        $today = $now->setTimezone($zone)->format('Y-m-d');
        $first = self::sendDay($type, $today);
        $sendDay = self::sendAt($first, $zone)->greaterThan($now) ? self::sendDay($type, self::addDays($first, -1)) : $first;
        $sendDate = self::date($sendDay);

        [$from, $to] = match ($type) {
            ReportFrequency::Daily => [self::addDays($sendDay, -1), self::addDays($sendDay, -1)],
            ReportFrequency::Weekly => [self::addDays($sendDay, -7), self::addDays($sendDay, -1)],
            ReportFrequency::Monthly => [$sendDate->subMonthNoOverflow()->format('Y-m-d'), self::addDays($sendDay, -1)],
            ReportFrequency::Shift => throw new InvalidArgumentException('A shift report has no schedule.'),
        };

        return new self($type, $from, $to, self::sendAt($sendDay, $zone));
    }

    /** The send day of the type on or before the date: the date itself, its week's Monday, its month's 1st. */
    private static function sendDay(ReportFrequency $type, string $date): string
    {
        $day = self::date($date);

        return match ($type) {
            ReportFrequency::Daily => $date,
            ReportFrequency::Weekly => $day->subDays($day->dayOfWeekIso - 1)->format('Y-m-d'),
            ReportFrequency::Monthly => $day->startOfMonth()->format('Y-m-d'),
            ReportFrequency::Shift => throw new InvalidArgumentException('A shift report has no schedule.'),
        };
    }

    /** 10:00 local on the date, by the schedule rules (should it not exist). */
    private static function sendAt(string $date, DateTimeZone $zone): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC(Expansion::resolve(self::date($date)->setTime(self::SEND_HOUR, 0)->getTimestamp(), $zone));
    }

    private static function addDays(string $date, int $days): string
    {
        return self::date($date)->addDays($days)->format('Y-m-d');
    }

    /** A local date as midnight of a naive (UTC) clock. */
    private static function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC') ?: throw new InvalidArgumentException("Not a date: {$date}");
    }
}
