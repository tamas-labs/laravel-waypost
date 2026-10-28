<?php

declare(strict_types=1);

namespace Waypost\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use Waypost\Models\Concerns\HasPrefixedTable;
use Waypost\Models\Concerns\HasUuidV7PrimaryKey;
use Waypost\Models\Concerns\StoresUtcMilliseconds;

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
}
