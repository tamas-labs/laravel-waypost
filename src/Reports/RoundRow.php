<?php

declare(strict_types=1);

namespace Waypost\Reports;

use Carbon\CarbonImmutable;
use Waypost\Models\Round;
use Waypost\Models\RouteVersion;
use Waypost\Models\Scan;

/**
 * A round of a report: an expected time of a schedule, a round the phone opened, or both (matched by schedule and
 * scheduled_at).
 */
final readonly class RoundRow
{
    /**
     * @param  list<Scan>  $scans  of the round, in time order
     * @param  list<string>  $missingCheckpointIds  the points of the route version without a scan, in route order
     */
    public function __construct(
        /** The local date (Y-m-d) the round's window starts on. */
        public string $startDay,
        public string $routeId,
        public string $scheduleId,
        public CarbonImmutable $scheduledAt,
        public ?int $timeLimitMin,
        public CarbonImmutable $deadline,
        /** The time comes from the schedule as it is now (false: only the phone's round has it). */
        public bool $expected,
        public ?Round $round,
        /** The round's version, or the route's version at the expected time. */
        public ?RouteVersion $version,
        public RoundOutcome $outcome,
        public array $scans,
        public array $missingCheckpointIds,
    ) {
    }
}
