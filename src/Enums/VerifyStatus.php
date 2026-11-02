<?php

declare(strict_types=1);

namespace Waypost\Enums;

use LogicException;

/** Result of the server-side verification of a chain entry. */
enum VerifyStatus: string
{
    case Ok = 'ok';
    case BadHmac = 'bad_hmac';
    case Gap = 'gap';
    case Fork = 'fork';

    /** The code in the rejected list of the push response. */
    public function errorCode(): ErrorCode
    {
        return match ($this) {
            self::Gap => ErrorCode::ChainGap,
            self::Fork => ErrorCode::ChainFork,
            self::BadHmac => ErrorCode::BadHmac,
            self::Ok => throw new LogicException('An ok entry is not rejected.'),
        };
    }
}
