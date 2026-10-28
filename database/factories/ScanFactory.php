<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Enums\ChainEntryType;
use Waypost\Enums\ScanMethod;
use Waypost\Models\ChainEntry;
use Waypost\Models\Checkpoint;
use Waypost\Models\Round;
use Waypost\Models\Scan;
use Waypost\Support\Time;

/** @extends Factory<Scan> */
final class ScanFactory extends Factory
{
    protected $model = Scan::class;

    public function definition(): array
    {
        return [
            'round_id' => Round::factory(),
            'checkpoint_id' => Checkpoint::factory(),
            'method' => ScanMethod::Nfc,
            'scanned_at' => Time::now(),
            'lat_e7' => null,
            'lng_e7' => null,
            'accuracy_dm' => null,
            'is_mock' => false,
            'flags' => [],
            'note' => null,
            'chain_entry_id' => ChainEntry::factory()->state(['type' => ChainEntryType::Scan]),
        ];
    }
}
