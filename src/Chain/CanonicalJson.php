<?php

declare(strict_types=1);

namespace Waypost\Chain;

use InvalidArgumentException;
use JsonException;

/**
 * RFC 8785 (JCS) as Waypost restricts it for hashed data (waypost-schema docs/decisions.md, "A kanonikus JSON pontos
 * szabályai"): keys sorted at every level, no whitespace, strings as ECMAScript JSON.stringify writes them, and numbers
 * only as integers within ±(2^53 − 1). The input is decoded JSON in arrays. Every key of hashed data is ASCII, so the
 * byte order of ksort() equals the UTF-16 order of the RFC.
 */
final class CanonicalJson
{
    private const int MAX_SAFE_INTEGER = 9007199254740991;

    private const int FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

    private function __construct()
    {
    }

    /**
     * @throws InvalidArgumentException for what hashed data may not contain
     * @throws JsonException for a string that is not valid UTF-8
     */
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), self::FLAGS);
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::normalize(...), $value);
        }
        if (is_float($value)) {
            // 1.0 is the integer 1 in JSON Schema and in the RFC's output; json_decode() only reads it as a float.
            if (floor($value) !== $value || abs($value) > self::MAX_SAFE_INTEGER) {
                throw new InvalidArgumentException('A floating-point number in hashed data.');
            }

            return (int) $value;
        }
        if (is_int($value) && abs($value) > self::MAX_SAFE_INTEGER) {
            throw new InvalidArgumentException('An integer outside ±(2^53 − 1) in hashed data.');
        }
        if (is_int($value) || is_string($value) || is_bool($value) || $value === null) {
            return $value;
        }

        throw new InvalidArgumentException('Not a JSON value: ' . get_debug_type($value));
    }
}
