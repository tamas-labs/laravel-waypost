<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Enums\ErrorCode;
use Waypost\Models\Device;
use Waypost\Models\DeviceDiagnostic;
use Waypost\Support\Time;
use Waypost\Support\Uuid;

/**
 * A fork reported by a device.
 *
 * @extends Factory<DeviceDiagnostic>
 */
final class DeviceDiagnosticFactory extends Factory
{
    protected $model = DeviceDiagnostic::class;

    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'code' => ErrorCode::ChainFork,
            'entry_id' => Uuid::v7(),
            'seq' => 2,
            'expected_seq' => null,
            'server_last_seq' => 1,
            'rejected_count' => 1,
            'occurred_at' => Time::now(),
            'received_at' => Time::now(),
        ];
    }
}
