<?php

declare(strict_types=1);

namespace Waypost\Enums;

/**
 * A flag the server sets on a chain entry (chain_entries.flags). The app's own flags are in the hashed payload, these
 * never (waypost-schema docs/decisions.md, "A scan payload belső szabályai").
 */
enum ChainFlag: string
{
    case TimeSuspect = 'time_suspect';
    case TooFast = 'too_fast';
    case FarFromCheckpoint = 'far_from_checkpoint';
    case LateSync = 'late_sync';
}
