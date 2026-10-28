<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Waypost\Database\Factories\RouteVersionCheckpointFactory;
use Waypost\Models\Concerns\HasPrefixedTable;

/**
 * A checkpoint at a position (from 1) of a route version. No id of its own: the key is
 * (route_version_id, position), and it never changes after the version is created.
 *
 * @property string $route_version_id
 * @property string $checkpoint_id
 * @property int $position
 * @property int|null $walk_time_from_prev_s
 */
#[UseFactory(RouteVersionCheckpointFactory::class)]
final class RouteVersionCheckpoint extends Pivot
{
    /** @use HasFactory<RouteVersionCheckpointFactory> */
    use HasFactory;
    use HasPrefixedTable;

    protected $table = 'route_version_checkpoints';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'walk_time_from_prev_s' => 'integer',
        ];
    }

    /** @return BelongsTo<RouteVersion, $this> */
    public function routeVersion(): BelongsTo
    {
        return $this->belongsTo(RouteVersion::class);
    }

    /** @return BelongsTo<Checkpoint, $this> */
    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(Checkpoint::class);
    }
}
