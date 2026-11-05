<?php

declare(strict_types=1);

namespace Waypost\Chain;

use Illuminate\Contracts\Encryption\DecryptException;
use RuntimeException;
use Waypost\Models\Device;

/**
 * The device secret cannot be decrypted, so no entry of the phone can be checked: the application key changed without
 * the old one in APP_PREVIOUS_KEYS (README, Üzemeltetés). Reported by the host as an error; the phone gets
 * server_error with a message that says what to do.
 */
final class UnreadableDeviceSecret extends RuntimeException
{
    public function __construct(public readonly Device $device, DecryptException $previous)
    {
        parent::__construct(
            "The secret of device {$device->id} cannot be decrypted: the APP_KEY changed without the old key in "
            . 'APP_PREVIOUS_KEYS. Put the old key back there, or enroll the phone again.',
            previous: $previous,
        );
    }
}
