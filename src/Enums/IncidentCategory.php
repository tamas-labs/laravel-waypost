<?php

declare(strict_types=1);

namespace Waypost\Enums;

/** Category of an incident reported by a guard. */
enum IncidentCategory: string
{
    case Vandalism = 'vandalism';
    case OpenDoor = 'open_door';
    case Water = 'water';
    case Intruder = 'intruder';
    case DamagedTag = 'damaged_tag';
    case Other = 'other';
}
