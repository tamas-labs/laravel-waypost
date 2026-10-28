<?php

declare(strict_types=1);

namespace Waypost\Schedule;

/**
 * The default time limit of a new schedule (spec: Állapotgépek / Menetrend → körök):
 * ceil(walk_duration_s × 1.5 / 300) × 5 minutes from the route's current version, 20 without a measured walk,
 * at least 5 and at most 1440. Computed in integers (schedule/test-vectors.json: time_limit_default).
 */
final class TimeLimit
{
    public const int WITHOUT_MEASUREMENT = 20;

    public const int MIN = 5;

    public const int MAX = 1440;

    private function __construct()
    {
    }

    public static function default(?int $walkDurationS): int
    {
        if ($walkDurationS === null) {
            return self::WITHOUT_MEASUREMENT;
        }

        // ceil(walk × 1.5 / 300) = ceil(walk / 200)
        return max(self::MIN, min(self::MAX, intdiv($walkDurationS + 199, 200) * 5));
    }
}
