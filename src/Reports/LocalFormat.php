<?php

declare(strict_types=1);

namespace Waypost\Reports;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/** Times and dates of a report as the site's clock shows them, in Hungarian (spec: minden időpont a telephely időzónájában). */
final readonly class LocalFormat
{
    private const array MONTHS = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus',
        'szeptember', 'október', 'november', 'december'];

    private const array WEEKDAYS = ['hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat', 'vasárnap'];

    private const array WEEKDAYS_SHORT = ['H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'];

    private DateTimeZone $zone;

    public function __construct(string $timezone)
    {
        $this->zone = new DateTimeZone($timezone);
    }

    public function local(DateTimeInterface $time): CarbonImmutable
    {
        return CarbonImmutable::instance($time)->setTimezone($this->zone);
    }

    /** 21:03 */
    public function time(?DateTimeInterface $time): ?string
    {
        return $time === null ? null : $this->local($time)->format('H:i');
    }

    /** 2026. október 26. */
    public function dateOf(DateTimeInterface $time): string
    {
        return self::date($this->local($time)->format('Y-m-d'));
    }

    /** 2026. október 26. (a local date, Y-m-d) */
    public static function date(string $date): string
    {
        [$year, $month, $day] = self::parts($date);

        return "{$year}. " . self::MONTHS[$month - 1] . " {$day}.";
    }

    /** 2026. október 26., hétfő */
    public static function dateWithWeekday(string $date): string
    {
        return self::date($date) . ', ' . self::WEEKDAYS[self::naive($date)->dayOfWeekIso - 1];
    }

    /** H, K, Sze, Cs, P, Szo, V (the app's weekday capsules) */
    public static function weekdayShort(string $date): string
    {
        return self::WEEKDAYS_SHORT[self::naive($date)->dayOfWeekIso - 1];
    }

    /**
     * The local dates from the first to the last, inclusive.
     *
     * @return list<string>
     */
    public static function days(string $from, string $to): array
    {
        $days = [];
        for ($day = self::naive($from), $last = self::naive($to); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    /** október 26. (in a list of one period's days) */
    public static function shortDate(string $date): string
    {
        [, $month, $day] = self::parts($date);

        return self::MONTHS[$month - 1] . " {$day}.";
    }

    /** 2026. október 26–27., 2026. október 31. – november 1., 2026. december 28. – 2027. január 3. */
    public static function dateRange(string $from, string $to): string
    {
        if ($from === $to) {
            return self::date($from);
        }
        [$fromYear, $fromMonth, $fromDay] = self::parts($from);
        [$toYear, $toMonth, $toDay] = self::parts($to);

        return match (true) {
            $fromYear !== $toYear => self::date($from) . ' – ' . self::date($to),
            $fromMonth !== $toMonth => self::date($from) . ' – ' . self::MONTHS[$toMonth - 1] . " {$toDay}.",
            default => "{$fromYear}. " . self::MONTHS[$fromMonth - 1] . " {$fromDay}–{$toDay}.",
        };
    }

    /** 2026. október */
    public static function month(string $date): string
    {
        [$year, $month] = self::parts($date);

        return "{$year}. " . self::MONTHS[$month - 1];
    }

    private static function naive(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC') ?: throw new InvalidArgumentException("Not a date: {$date}");
    }

    /** @return array{int, int, int} */
    private static function parts(string $date): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) !== 1) {
            throw new InvalidArgumentException("Not a date: {$date}");
        }

        return [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
    }
}
