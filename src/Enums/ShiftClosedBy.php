<?php

declare(strict_types=1);

namespace Waypost\Enums;

/** How a shift was closed. */
enum ShiftClosedBy: string
{
    case Guard = 'guard';
    case Handover = 'handover';
    case Auto = 'auto';
}
