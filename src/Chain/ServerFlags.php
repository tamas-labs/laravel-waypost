<?php

declare(strict_types=1);

namespace Waypost\Chain;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Waypost\Models\ChainEntry;
use Waypost\Models\Checkpoint;
use Waypost\Support\Time;

/**
 * The rules of the flags the server sets (spec: Láncolt napló / Jelölések). A flag never blocks anything, it only shows
 * in the reports. "More than" and "less than" in the spec are strict: a value exactly at a limit is not flagged.
 */
final class ServerFlags
{
    private const int TIME_SUSPECT_MS = 120_000;

    /** 40% of the trained walk time, per second of it in milliseconds. */
    private const int TOO_FAST_MS_PER_WALK_SECOND = 400;

    private const int MIN_DISTANCE_M = 50;

    /** The accuracies must be below 50 m, in decimetres. */
    private const int MAX_ACCURACY_DM = 500;

    private const int LATE_SYNC_HOURS = 12;

    private const float EARTH_RADIUS_M = 6_371_008.8;

    private function __construct()
    {
    }

    /** The wall clock differs by more than 2 minutes from the estimate of the same boot, or from the GNSS time. */
    public static function timeSuspect(ChainEntry $entry): bool
    {
        $deviceTime = Time::milliseconds($entry->device_time);
        foreach ([TrustedTime::estimate($entry), $entry->gnss_time] as $reference) {
            if ($reference !== null && abs(Time::milliseconds($reference) - $deviceTime) > self::TIME_SUSPECT_MS) {
                return true;
            }
        }

        return false;
    }

    /** Less time passed between two scans than 40% of the trained walk time between their checkpoints. */
    public static function tooFast(DateTimeInterface $previous, DateTimeInterface $current, ?int $walkSeconds): bool
    {
        return $walkSeconds !== null
            && Time::milliseconds($current) - Time::milliseconds($previous) < $walkSeconds * self::TOO_FAST_MS_PER_WALK_SECOND;
    }

    /**
     * The scan is farther from the trained location than max(50 m, 3 × the scan's accuracy), and both accuracies are
     * below 50 m. Without a location or an accuracy on either side there is nothing to compare.
     */
    public static function farFromCheckpoint(?int $latE7, ?int $lngE7, ?int $accuracyDm, Checkpoint $checkpoint): bool
    {
        if ($latE7 === null || $lngE7 === null || $accuracyDm === null
            || $checkpoint->lat_e7 === null || $checkpoint->lng_e7 === null || $checkpoint->accuracy_dm === null
            || $accuracyDm >= self::MAX_ACCURACY_DM || $checkpoint->accuracy_dm >= self::MAX_ACCURACY_DM) {
            return false;
        }

        return self::distanceM($latE7, $lngE7, $checkpoint->lat_e7, $checkpoint->lng_e7) > max(self::MIN_DISTANCE_M, 3 * $accuracyDm / 10);
    }

    /** The entry arrived more than 12 hours after the shift closed. */
    public static function lateSync(DateTimeInterface $receivedAt, DateTimeInterface $closedAt): bool
    {
        return Time::milliseconds($receivedAt) > Time::milliseconds(CarbonImmutable::instance($closedAt)->addHours(self::LATE_SYNC_HOURS));
    }

    /** Haversine distance in metres; the coordinates are degrees × 10^7. */
    public static function distanceM(int $latE7, int $lngE7, int $otherLatE7, int $otherLngE7): float
    {
        $lat = deg2rad($latE7 / 1e7);
        $otherLat = deg2rad($otherLatE7 / 1e7);
        $a = sin(($otherLat - $lat) / 2) ** 2 + cos($lat) * cos($otherLat) * sin(deg2rad(($otherLngE7 - $lngE7) / 1e7) / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }
}
