<?php

declare(strict_types=1);

namespace Waypost\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use Waypost\Models\Concerns\HasPrefixedTable;
use Waypost\Models\Concerns\HasUuidV7PrimaryKey;
use Waypost\Models\Concerns\StoresUtcMilliseconds;
use Waypost\Support\Uuid;

/**
 * Sanctum token of a User (supervisor ability) or a Device (device ability), in waypost_personal_access_tokens.
 *
 * @property string $id
 * @property string $tokenable_type
 * @property string $tokenable_id
 * @property string $name
 * @property string $token
 * @property list<string> $abilities
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasPrefixedTable;
    use HasUuidV7PrimaryKey;
    use StoresUtcMilliseconds;

    protected $table = 'personal_access_tokens';

    /**
     * The token of a plain-text "id|secret" (as createToken() returns it). Unlike Sanctum's, a malformed ID is
     * no match rather than a query, which PostgreSQL would reject for the uuid column.
     *
     * @param  string  $token
     */
    public static function findToken($token): ?self
    {
        if (!str_contains($token, '|')) {
            return null;
        }

        [$id, $secret] = explode('|', $token, 2);
        $instance = Uuid::isV7($id) ? self::query()->find($id) : null;

        return $instance !== null && hash_equals($instance->token, hash('sha256', $secret)) ? $instance : null;
    }
}
