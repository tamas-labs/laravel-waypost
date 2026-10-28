<?php

declare(strict_types=1);

namespace Waypost\Enums;

/** Report period: of report subscriptions and of the reports themselves. */
enum ReportFrequency: string
{
    case Shift = 'shift';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
}
