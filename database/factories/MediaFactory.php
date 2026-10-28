<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Models\Incident;
use Waypost\Models\Media;
use Waypost\Support\Time;

/**
 * A photo of an incident that is not uploaded yet; uploaded() fills the file fields.
 *
 * @extends Factory<Media>
 */
final class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        return [
            'device_id' => null,
            'owner_type' => Incident::class,
            'owner_id' => Incident::factory(),
            'sha256' => bin2hex(random_bytes(32)),
            'taken_at' => Time::now(),
        ];
    }

    public function uploaded(): self
    {
        return $this->state(fn (array $attributes) => [
            'mime' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(100_000, 400_000),
            'path' => 'waypost/media/' . $attributes['sha256'] . '.jpg',
            'uploaded_at' => Time::now(),
        ]);
    }
}
