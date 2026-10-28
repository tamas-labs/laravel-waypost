<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Models\Checkpoint;
use Waypost\Models\RouteVersion;
use Waypost\Models\RouteVersionCheckpoint;

/**
 * The next position of the version (1 for an empty version).
 *
 * @extends Factory<RouteVersionCheckpoint>
 */
final class RouteVersionCheckpointFactory extends Factory
{
    protected $model = RouteVersionCheckpoint::class;

    public function definition(): array
    {
        return [
            'route_version_id' => RouteVersion::factory(),
            'checkpoint_id' => Checkpoint::factory(),
            'position' => fn (array $attributes) => RouteVersionCheckpoint::query()
                ->where('route_version_id', $attributes['route_version_id'])
                ->max('position') + 1,
            'walk_time_from_prev_s' => null,
        ];
    }
}
