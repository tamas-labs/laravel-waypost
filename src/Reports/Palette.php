<?php

declare(strict_types=1);

namespace Waypost\Reports;

/**
 * The colours of the report templates: the light theme's state styles (design src/theme/tokens.ts, palette.light;
 * email/shift-report.blade.php). A state has a colour, and always a text too.
 */
final class Palette
{
    /** By RoundOutcome: badge text and background. */
    public const array STATUS = [
        'completed' => ['fg' => '#157F4A', 'bg' => '#D7F2E3'],
        'incomplete' => ['fg' => '#B4470F', 'bg' => '#FDE6D8'],
        'missed' => ['fg' => '#C0262D', 'bg' => '#FBE0E1'],
        'pending' => ['fg' => '#8A6100', 'bg' => '#FBF0C9'],
        'upcoming' => ['fg' => '#4F5B73', 'bg' => '#EEF2F8'],
    ];

    /** By ReportContent's point style: flag text and row background. */
    public const array FLAG = [
        'absent' => ['fg' => '#C0262D', 'row' => '#FBE0E1'],
        'manual' => ['fg' => '#6B46C1', 'row' => '#ECE5FB'],
        'suspicious' => ['fg' => '#8A6100', 'row' => '#FBF0C9'],
        'warning' => ['fg' => '#B4470F', 'row' => '#FDE6D8'],
    ];

    private function __construct()
    {
    }
}
