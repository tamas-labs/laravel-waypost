<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Models\Route;
use Waypost\Models\RouteVersion;
use Waypost\Models\User;

/**
 * The next version of the route (1 for a new route).
 *
 * @extends Factory<RouteVersion>
 */
final class RouteVersionFactory extends Factory
{
    protected $model = RouteVersion::class;

    public function definition(): array
    {
        return [
            'route_id' => Route::factory(),
            'version' => fn (array $attributes) => RouteVersion::query()
                ->where('route_id', $attributes['route_id'])
                ->max('version') + 1,
            'walk_duration_s' => fake()->numberBetween(300, 1800),
            'created_by' => User::factory()->supervisor(),
        ];
    }
}
