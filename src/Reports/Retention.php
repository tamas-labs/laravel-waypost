<?php

declare(strict_types=1);

namespace Waypost\Reports;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Waypost\Enums\ReportFrequency;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Site;

/**
 * How long a site's raw data is kept (spec: Adatmegőrzés): max(31, 2 × the longest subscribed period in days), with
 * shift = daily = 1, weekly = 7 and monthly = 31 days; so 62 days with a monthly report, 31 otherwise. The same as
 * the app's src/domain/report-settings.ts shows the supervisor.
 */
final class Retention
{
    public const int MIN_DAYS = 31;

    private function __construct()
    {
    }

    public static function periodDays(ReportFrequency $frequency): int
    {
        return match ($frequency) {
            ReportFrequency::Shift, ReportFrequency::Daily => 1,
            ReportFrequency::Weekly => 7,
            ReportFrequency::Monthly => 31,
        };
    }

    public static function days(Site $site): int
    {
        $longest = ReportSubscription::query()
            ->where('site_id', $site->id)
            ->distinct()
            ->pluck('frequency')
            ->reduce(fn (int $days, ReportFrequency $frequency): int => max($days, self::periodDays($frequency)), 0);

        return max(self::MIN_DAYS, 2 * $longest);
    }

    /** Until when data of the site from the given time is kept. */
    public static function until(Site $site, DateTimeInterface $time): CarbonImmutable
    {
        return CarbonImmutable::instance($time)->utc()->addDays(self::days($site));
    }
}
