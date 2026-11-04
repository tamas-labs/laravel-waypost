<?php

declare(strict_types=1);

namespace Waypost\Schedule;

use Carbon\CarbonImmutable;

/** One expected round of a schedule (schedule/test-vectors.json: expected[]). */
final readonly class ExpectedRound
{
    public function __construct(
        /** Local date (Y-m-d) the round's window starts on: the day the round belongs to. */
        public string $startDay,
        /** The wall-clock time of the schedule the round comes from (HH:MM). */
        public string $wallTime,
        /** The instant, in UTC. */
        public CarbonImmutable $utc,
        /** The same instant in the site's time zone. */
        public CarbonImmutable $local,
    ) {
    }
}
