<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Enums\ReportFrequency;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Site;
use Waypost\Models\User;

/** @extends Factory<ReportSubscription> */
final class ReportSubscriptionFactory extends Factory
{
    protected $model = ReportSubscription::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->supervisor(),
            'site_id' => Site::factory(),
            'frequency' => ReportFrequency::Daily,
        ];
    }
}
