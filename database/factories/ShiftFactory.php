<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Models\ChainEntry;
use Waypost\Models\Device;
use Waypost\Models\Shift;
use Waypost\Models\User;
use Waypost\Support\Time;

/**
 * An open shift on the device's site.
 *
 * @extends Factory<Shift>
 */
final class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'site_id' => fn (array $attributes) => Device::query()->findOrFail((string) $attributes['device_id'])->site_id,
            'guard_id' => User::factory(),
            'started_at' => Time::now(),
            'ended_at' => null,
            'closed_by' => null,
            'chain_entry_id' => fn (array $attributes) => ChainEntry::factory()->create([
                'device_id' => $attributes['device_id'],
            ])->id,
        ];
    }
}
