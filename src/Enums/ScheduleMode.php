<?php

declare(strict_types=1);

namespace Waypost\Enums;

/** list: explicit start times; interval: from, to and every. */
enum ScheduleMode: string
{
    case List = 'list';
    case Interval = 'interval';
}
