<?php

declare(strict_types=1);

namespace Waypost\Enums;

/** State of a round. */
enum RoundStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Incomplete = 'incomplete';
    case Missed = 'missed';
}
