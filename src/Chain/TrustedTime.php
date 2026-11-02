<?php

declare(strict_types=1);

namespace Waypost\Chain;

use Carbon\CarbonImmutable;
use Waypost\Models\ChainEntry;
use Waypost\Support\Time;

/**
 * The time of a chain entry as far as it can be trusted, from its own fields. The same calculation as entryTrustedTime
 * of the app (src/time/trusted-time.ts): a difference would make the phone and the reports disagree.
 */
final class TrustedTime
{
    private function __construct()
    {
    }

    /**
     * The last server time plus the monotonic time elapsed since, if the reference comes from the same boot (the uptime
     * restarts at every boot) and the uptime did not go backwards; otherwise the wall clock of the phone.
     */
    public static function of(ChainEntry $entry): CarbonImmutable
    {
        $estimate = $entry->uptime_ms >= (int) $entry->ref_uptime_ms ? self::estimate($entry) : null;

        return $estimate ?? CarbonImmutable::instance($entry->device_time)->utc();
    }

    /** ref_server_time + uptime_ms − ref_uptime_ms, if the reference comes from the same boot. */
    public static function estimate(ChainEntry $entry): ?CarbonImmutable
    {
        if ($entry->ref_server_time === null || $entry->ref_uptime_ms === null || $entry->ref_boot_id !== $entry->boot_id) {
            return null;
        }

        return Time::fromMilliseconds(Time::milliseconds($entry->ref_server_time) + $entry->uptime_ms - $entry->ref_uptime_ms);
    }
}
