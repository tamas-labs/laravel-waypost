<?php

declare(strict_types=1);

namespace Waypost\Enums;

/** Type of a chain entry; the database column is a string, so a new type needs no migration. */
enum ChainEntryType: string
{
    case ShiftStarted = 'shift_started';
    case ShiftClosed = 'shift_closed';
    case RoundOpened = 'round_opened';
    case Scan = 'scan';
    case Incident = 'incident';
    case RoundClosed = 'round_closed';
}
