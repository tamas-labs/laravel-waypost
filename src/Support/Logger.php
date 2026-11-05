<?php

declare(strict_types=1);

namespace Waypost\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * The package's log: the channel of waypost.log_channel (WAYPOST_LOG_CHANNEL), or the host's default. Messages start
 * with "Waypost:", the context holds the IDs (device, entry, site, report), never secrets or personal data beyond IDs.
 */
final class Logger
{
    private function __construct()
    {
    }

    public static function channel(): LoggerInterface
    {
        $channel = config('waypost.log_channel');

        return Log::channel(is_string($channel) && $channel !== '' ? $channel : null);
    }
}
