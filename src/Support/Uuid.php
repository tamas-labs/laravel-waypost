<?php

declare(strict_types=1);

namespace Waypost\Support;

use Illuminate\Support\Str;

/**
 * UUIDv7 identifiers: every primary key, because records are also created on devices while offline.
 * The only accepted text form is the canonical lowercase one (schema common/uuid.json), since IDs are
 * part of hashed chain entries and unique keys.
 */
final class Uuid
{
    /** The pattern of waypost/schema common/uuid.json; D: "$" must not match before a trailing newline. */
    public const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    private function __construct()
    {
    }

    public static function v7(): string
    {
        return Str::uuid7()->toString();
    }

    public static function isV7(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }
}
