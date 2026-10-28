<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\ShiftFactory;
use Waypost\Enums\ShiftClosedBy;

/**
 * Projection of shift_started / shift_closed entries.
 *
 * @property string $id
 * @property string $site_id
 * @property string $device_id
 * @property string $guard_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property ShiftClosedBy|null $closed_by
 * @property string $chain_entry_id
 */
#[UseFactory(ShiftFactory::class)]
final class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'closed_by' => ShiftClosedBy::class,
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<User, $this> */
    public function guardUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guard_id');
    }

    /** @return BelongsTo<ChainEntry, $this> */
    public function chainEntry(): BelongsTo
    {
        return $this->belongsTo(ChainEntry::class);
    }

    /** @return HasMany<Round, $this> */
    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }

    /** @return HasMany<Incident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }
}
