<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\RouteFactory;
use Waypost\Enums\OrderMode;

/**
 * A patrol route of a site. Its checkpoints live in immutable versions; current_version_id is the one
 * new rounds use.
 *
 * @property string $id
 * @property string $site_id
 * @property string $name
 * @property OrderMode $order_mode
 * @property string|null $current_version_id
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[UseFactory(RouteFactory::class)]
final class Route extends Model
{
    /** @use HasFactory<RouteFactory> */
    use HasFactory;
    use SoftDeletes;

    /** The database default, so a new model has it before it is read back. */
    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return [
            'order_mode' => OrderMode::class,
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<RouteVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(RouteVersion::class)->orderBy('version');
    }

    /** @return BelongsTo<RouteVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(RouteVersion::class, 'current_version_id');
    }

    /** @return HasMany<Schedule, $this> */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }
}
