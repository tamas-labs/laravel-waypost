<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\RouteVersionFactory;
use Waypost\Support\Table;

/**
 * An immutable version of a route: its checkpoints in order, with the walk times measured during
 * training. version counts from 1 within the route.
 *
 * @property string $id
 * @property string $route_id
 * @property int $version
 * @property int|null $walk_duration_s
 * @property string $created_by
 * @property Carbon|null $created_at
 */
#[UseFactory(RouteVersionFactory::class)]
final class RouteVersion extends Model
{
    /** @use HasFactory<RouteVersionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'walk_duration_s' => 'integer',
        ];
    }

    /** @return BelongsTo<Route, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<RouteVersionCheckpoint, $this> */
    public function points(): HasMany
    {
        return $this->hasMany(RouteVersionCheckpoint::class)->orderBy('position');
    }

    /** @return BelongsToMany<Checkpoint, $this, RouteVersionCheckpoint> */
    public function checkpoints(): BelongsToMany
    {
        return $this->belongsToMany(Checkpoint::class, Table::name('route_version_checkpoints'))
            ->using(RouteVersionCheckpoint::class)
            ->withPivot('position', 'walk_time_from_prev_s')
            ->orderByPivot('position');
    }

    /** @return HasMany<Round, $this> */
    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }
}
