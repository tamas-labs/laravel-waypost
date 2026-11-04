<?php

declare(strict_types=1);

namespace Waypost\Schedule;

use Carbon\CarbonImmutable;

/**
 * The deadline of a round (spec: Állapotgépek / Kör / Határidő), as the app's src/domain/deadline.ts computes it:
 * scheduled_at + time_limit_min; without a time limit the time of the route's next round of the same day (window
 * start day), and for the day's last round scheduled_at + 60 minutes.
 */
final class Deadline
{
    public const int LAST_ROUND_GRACE_MIN = 60;

    private function __construct()
    {
    }

    /** @param  iterable<CarbonImmutable>  $dayTimes  the times of the route's rounds of the same start day, in any order */
    public static function of(CarbonImmutable $scheduledAt, ?int $timeLimitMin, iterable $dayTimes): CarbonImmutable
    {
        if ($timeLimitMin !== null) {
            return $scheduledAt->addMinutes($timeLimitMin);
        }

        $next = null;
        foreach ($dayTimes as $time) {
            if ($time->greaterThan($scheduledAt) && ($next === null || $time->lessThan($next))) {
                $next = $time;
            }
        }

        return $next ?? $scheduledAt->addMinutes(self::LAST_ROUND_GRACE_MIN);
    }
}
