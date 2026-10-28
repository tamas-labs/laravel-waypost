<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Enums\RoundStatus;
use Waypost\Models\Round;
use Waypost\Models\RouteVersion;
use Waypost\Models\Schedule;
use Waypost\Models\Shift;
use Waypost\Support\Time;

/** @extends Factory<Round> */
final class RoundFactory extends Factory
{
    protected $model = Round::class;

    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'route_version_id' => RouteVersion::factory(),
            'schedule_id' => Schedule::factory(),
            'scheduled_at' => Time::now(),
            'time_limit_min' => 20,
            'status' => RoundStatus::Open,
        ];
    }
}
