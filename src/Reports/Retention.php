<?php

declare(strict_types=1);

namespace Waypost\Reports;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Waypost\Enums\ReportFrequency;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Site;
use Waypost\Support\Time;

/**
 * How long a site's raw data is kept (spec: Adatmegőrzés): max(31, 2 × the longest subscribed period in days), with
 * shift = daily = 1, weekly = 7 and monthly = 31 days; so 62 days with a monthly report, 31 otherwise. The same as
 * the app's src/domain/report-settings.ts shows the supervisor, but for the grace period after a cancellation.
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

    /**
     * The site's retention now. A cancelled (deleted) subscription counts on for its own retention after it was
     * cancelled: the data recorded while it ran is kept as long as it promised (WP-BE-039).
     */
    public static function days(Site $site): int
    {
        $now = Time::now();
        $longest = 0;
        $subscriptions = ReportSubscription::withTrashed()
            ->where('site_id', $site->id)
            ->where(fn (Builder $current) => $current->whereNull('deleted_at')
                ->orWhere('deleted_at', '>', $now->subDays(2 * self::periodDays(ReportFrequency::Monthly))->format(Time::DATABASE_FORMAT)))
            ->get(['frequency', 'deleted_at']);
        foreach ($subscriptions as $subscription) {
            $days = self::periodDays($subscription->frequency);
            if ($subscription->deleted_at === null || $subscription->deleted_at->greaterThan($now->subDays(2 * $days))) {
                $longest = max($longest, $days);
            }
        }

        return max(self::MIN_DAYS, 2 * $longest);
    }

    /**
     * Until when data of the site from the given time is kept.
     *
     * @param  int|null  $days  the site's days() already counted (a report with many photos), or null to count them
     */
    public static function until(Site $site, DateTimeInterface $time, ?int $days = null): CarbonImmutable
    {
        return CarbonImmutable::instance($time)->utc()->addDays($days ?? self::days($site));
    }
}
