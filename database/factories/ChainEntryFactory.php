<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Waypost\Enums\ChainEntryType;
use Waypost\Enums\VerifyStatus;
use Waypost\Models\ChainEntry;
use Waypost\Models\Device;
use Waypost\Support\Time;

/**
 * The next entry of the device's chain as stored rows; payload and hashes are placeholders, not a valid
 * HMAC chain (the chain tests use the waypost/schema test vectors).
 *
 * @extends Factory<ChainEntry>
 */
final class ChainEntryFactory extends Factory
{
    protected $model = ChainEntry::class;

    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'seq' => fn (array $attributes) => ChainEntry::query()
                ->where('device_id', $attributes['device_id'])
                ->max('seq') + 1,
            'type' => ChainEntryType::ShiftStarted,
            'payload' => [],
            'device_time' => Time::now(),
            'uptime_ms' => fake()->numberBetween(60_000, 86_400_000),
            'boot_id' => (string) Str::uuid(),
            'prev_hash' => str_repeat('0', 64),
            'hash' => bin2hex(random_bytes(32)),
            'received_at' => Time::now(),
            'verify_status' => VerifyStatus::Ok,
            'flags' => [],
        ];
    }
}
