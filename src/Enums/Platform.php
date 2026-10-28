<?php

declare(strict_types=1);

namespace Waypost\Enums;

/** Operating system of an enrolled device. */
enum Platform: string
{
    case Ios = 'ios';
    case Android = 'android';
}
