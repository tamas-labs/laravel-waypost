<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Enums\ScheduleMode;
use Waypost\Models\Route;
use Waypost\Models\Schedule;

/**
 * Every day at 20:00 and 22:00 by default; interval() for every N minutes between two times.
 *
 * @extends Factory<Schedule>
 */
final class ScheduleFactory extends Factory
{
    protected $model = Schedule::class;

    public function definition(): array
    {
        return [
            'route_id' => Route::factory(),
            'name' => 'Éjszakai körök',
            'weekdays' => 127,
            'mode' => ScheduleMode::List,
            'start_times' => ['20:00', '22:00'],
            'interval_from' => null,
            'interval_to' => null,
            'interval_every_min' => null,
            'time_limit_min' => 20,
            'active' => true,
        ];
    }

    public function interval(string $from = '20:00', string $to = '08:00', int $everyMin = 60): self
    {
        return $this->state(fn () => [
            'mode' => ScheduleMode::Interval,
            'start_times' => null,
            'interval_from' => $from,
            'interval_to' => $to,
            'interval_every_min' => $everyMin,
        ]);
    }
}
