<?php

declare(strict_types=1);

namespace Waypost\Enums;

/** Whether the checkpoints of a route must be scanned in order. */
enum OrderMode: string
{
    case Free = 'free';
    case Fixed = 'fixed';
}
