<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\DeviceFactory;
use Waypost\Enums\Platform;
use Waypost\Models\Concerns\HasWaypostTokens;

/**
 * The site phone. It authenticates with its own Sanctum token (device ability) and signs its chain with
 * the secret (base64 of 32 random bytes, encrypted at rest). last_seq and last_hash are the head of the
 * verified chain; they survive the retention cleanup.
 *
 * @property string $id
 * @property string|null $site_id
 * @property string $name
 * @property Platform $platform
 * @property string $app_version
 * @property string $secret
 * @property string $enrolled_by
 * @property Carbon $enrolled_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_seen_at
 * @property int $last_seq
 * @property string|null $last_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[UseFactory(DeviceFactory::class)]
final class Device extends Model implements AuthenticatableContract
{
    use Authenticatable;
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;
    use HasWaypostTokens;
    use SoftDeletes;

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'secret' => 'encrypted',
            'enrolled_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_seq' => 'integer',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<User, $this> */
    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }

    /** @return HasMany<ChainEntry, $this> */
    public function chainEntries(): HasMany
    {
        return $this->hasMany(ChainEntry::class);
    }

    /** @return HasMany<Shift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }
}
