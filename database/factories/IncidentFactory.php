<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Enums\ChainEntryType;
use Waypost\Enums\IncidentCategory;
use Waypost\Models\ChainEntry;
use Waypost\Models\Incident;
use Waypost\Models\Shift;
use Waypost\Support\Time;

/** @extends Factory<Incident> */
final class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'category' => IncidentCategory::Other,
            'note' => 'Nyitva maradt a hátsó kapu.',
            'occurred_at' => Time::now(),
            'chain_entry_id' => ChainEntry::factory()->state(['type' => ChainEntryType::Incident]),
        ];
    }
}
