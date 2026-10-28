<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Models\Site;
use Waypost\Models\User;

/** @extends Factory<Site> */
final class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'address' => fake()->address(),
            'timezone' => 'Europe/Budapest',
            'no_data_alert_after_min' => 120,
            'created_by' => User::factory()->supervisor(),
        ];
    }
}
