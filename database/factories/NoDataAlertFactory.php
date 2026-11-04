<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Models\NoDataAlert;
use Waypost\Models\Site;
use Waypost\Support\Time;

/**
 * An alert sent for the 20:00 round of the day before.
 *
 * @extends Factory<NoDataAlert>
 */
final class NoDataAlertFactory extends Factory
{
    protected $model = NoDataAlert::class;

    public function definition(): array
    {
        $scheduledAt = Time::now()->subDay()->setTime(19, 0);

        return [
            'site_id' => Site::factory(),
            'window_day' => $scheduledAt->format('Y-m-d'),
            'scheduled_at' => $scheduledAt,
            'deadline' => $scheduledAt->addMinutes(30),
            'last_seen_at' => null,
            'recipients' => [fake()->safeEmail()],
            'sent_at' => $scheduledAt->addMinutes(150),
        ];
    }
}
