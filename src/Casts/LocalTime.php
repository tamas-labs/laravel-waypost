<?php

declare(strict_types=1);

namespace Waypost\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A wall-clock time of day in the API form HH:MM (common/local-time.json), stored in a time column
 * (which MySQL and PostgreSQL return as HH:MM:SS).
 *
 * @implements CastsAttributes<string|null, mixed>
 */
final class LocalTime implements CastsAttributes
{
    public const string PATTERN = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/D';

    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 5);
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Invalid local time for {$key}; expected HH:MM.");
        }

        return $value . ':00';
    }
}
