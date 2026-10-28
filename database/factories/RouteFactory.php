<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Enums\OrderMode;
use Waypost\Models\Route;
use Waypost\Models\Site;

/** @extends Factory<Route> */
final class RouteFactory extends Factory
{
    protected $model = Route::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => 'Útvonal ' . fake()->numberBetween(1, 99),
            'order_mode' => OrderMode::Free,
            'current_version_id' => null,
            'active' => true,
        ];
    }

    public function fixed(): self
    {
        return $this->state(fn () => ['order_mode' => OrderMode::Fixed]);
    }
}
