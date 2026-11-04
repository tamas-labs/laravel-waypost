<?php

declare(strict_types=1);

namespace Waypost\Reports;

use Waypost\Enums\RoundStatus;

/** What a report says about a round (a row of Summary). */
enum RoundOutcome: string
{
    case Completed = 'completed';
    case Incomplete = 'incomplete';
    /** The phone closed it without a scan, or an expected time has no round and its deadline has passed. */
    case Missed = 'missed';
    /** Opened, but the server has no close yet (in progress, or the phone has not sent it). */
    case Pending = 'pending';
    /** An expected time without a round whose deadline has not passed yet. */
    case Upcoming = 'upcoming';

    public static function of(RoundStatus $status): self
    {
        return match ($status) {
            RoundStatus::Completed => self::Completed,
            RoundStatus::Incomplete => self::Incomplete,
            RoundStatus::Missed => self::Missed,
            RoundStatus::Open, RoundStatus::InProgress => self::Pending,
        };
    }
}
