<?php

declare(strict_types=1);

namespace Waypost\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Sanctum\HasApiTokens;
use Waypost\Models\PersonalAccessToken;

/**
 * Sanctum API tokens kept in the package's own table (PersonalAccessToken). Sanctum's global token
 * model (Sanctum::usePersonalAccessTokenModel()) is left alone, so the host application's own Sanctum
 * tokens keep working in its personal_access_tokens table.
 */
trait HasWaypostTokens
{
    /** @use HasApiTokens<PersonalAccessToken> */
    use HasApiTokens;

    /** @return MorphMany<PersonalAccessToken, $this> */
    public function tokens(): MorphMany
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable');
    }
}
