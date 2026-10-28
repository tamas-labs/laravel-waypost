<?php

declare(strict_types=1);

namespace Waypost\Enums;

/** User role: supervisors configure and log in with a password, guards only with their PIN. */
enum Role: string
{
    case Supervisor = 'supervisor';
    case Guard = 'guard';
}
