<?php

declare(strict_types=1);

namespace Waypost\Enums;

/** Result of the server-side verification of a chain entry. */
enum VerifyStatus: string
{
    case Ok = 'ok';
    case BadHmac = 'bad_hmac';
    case Gap = 'gap';
    case Fork = 'fork';
}
