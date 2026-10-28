<?php

declare(strict_types=1);

namespace Waypost\Support;

/**
 * Names of the package's tables. Every table gets the configured prefix (waypost.table_prefix,
 * default "waypost_"); models (HasPrefixedTable) and migrations take their table names from here.
 */
final class Table
{
    private function __construct()
    {
    }

    public static function prefix(): string
    {
        return config()->string('waypost.table_prefix', 'waypost_');
    }

    /** Prefixed name of a table, e.g. "users" → "waypost_users". */
    public static function name(string $table): string
    {
        return self::prefix() . $table;
    }
}
