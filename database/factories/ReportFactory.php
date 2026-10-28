<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Enums\ReportFrequency;
use Waypost\Models\Report;
use Waypost\Models\Site;
use Waypost\Support\Time;

/**
 * A daily report of yesterday (UTC days, which is close enough for tests).
 *
 * @extends Factory<Report>
 */
final class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        $start = Time::now()->subDay()->startOfDay();

        return [
            'site_id' => Site::factory(),
            'type' => ReportFrequency::Daily,
            'period_start' => $start,
            'period_end' => $start->addDay(),
            'generated_at' => Time::now(),
            'sent_at' => null,
            'path' => null,
            'recipients' => [fake()->safeEmail()],
        ];
    }
}
