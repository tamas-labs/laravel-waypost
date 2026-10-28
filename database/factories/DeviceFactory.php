<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Enums\Platform;
use Waypost\Models\Device;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Time;

/** @extends Factory<Device> */
final class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => 'Telephelyi telefon',
            'platform' => fake()->randomElement(Platform::cases()),
            'app_version' => '1.0.0',
            'secret' => base64_encode(random_bytes(32)),
            'enrolled_by' => User::factory()->supervisor(),
            'enrolled_at' => Time::now(),
        ];
    }

    public function revoked(): self
    {
        return $this->state(fn () => ['revoked_at' => Time::now()]);
    }
}
