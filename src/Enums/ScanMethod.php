<?php

declare(strict_types=1);

namespace Waypost\Enums;

/** How a checkpoint was confirmed. */
enum ScanMethod: string
{
    case Nfc = 'nfc';
    case Qr = 'qr';
    case Manual = 'manual';
}
