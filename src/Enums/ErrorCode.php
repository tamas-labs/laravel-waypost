<?php

declare(strict_types=1);

namespace Waypost\Enums;

/**
 * Machine-readable error code of the API (schema common/error-code.json). Request-level codes come with their
 * HTTP status in api/error.json; chain_gap, chain_fork and bad_hmac only in the push response's rejected list.
 */
enum ErrorCode: string
{
    case Unauthenticated = 'unauthenticated';
    case DeviceRevoked = 'device_revoked';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case ValidationFailed = 'validation_failed';
    case Conflict = 'conflict';
    case ChainGap = 'chain_gap';
    case ChainFork = 'chain_fork';
    case BadHmac = 'bad_hmac';
    case RateLimited = 'rate_limited';
    case BadRequest = 'bad_request';
    case ServerError = 'server_error';

    /** HTTP status of a request-level error; bad_request and server_error keep the status of the actual error. */
    public function status(): int
    {
        return match ($this) {
            self::Unauthenticated, self::DeviceRevoked => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::Conflict => 409,
            self::ValidationFailed, self::ChainGap, self::ChainFork, self::BadHmac => 422,
            self::RateLimited => 429,
            self::BadRequest => 400,
            self::ServerError => 500,
        };
    }

    /** Default message, shown to the user. */
    public function message(): string
    {
        return match ($this) {
            self::Unauthenticated => 'Bejelentkezés szükséges.',
            self::DeviceRevoked => 'Ezt a telefont visszavonták.',
            self::Forbidden => 'Ehhez nincs jogosultságod.',
            self::NotFound => 'A keresett elem nem található.',
            self::ValidationFailed => 'A megadott adatok hibásak.',
            self::Conflict => 'Az adat ütközik egy meglévővel.',
            self::ChainGap => 'Hiányzó bejegyzés a láncban.',
            self::ChainFork => 'A lánc elágazott.',
            self::BadHmac => 'Hibás aláírás.',
            self::RateLimited => 'Túl sok kérés, próbáld újra később.',
            self::BadRequest => 'Hibás kérés.',
            self::ServerError => 'Váratlan szerverhiba, próbáld újra később.',
        };
    }
}
