<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Waypost\Enums\TagType;
use Waypost\Models\Checkpoint;
use Waypost\Models\Site;
use Waypost\Support\Uuid;

/**
 * An NFC checkpoint with a random 7-byte UID somewhere in Budapest by default; qr() for a QR checkpoint.
 *
 * @extends Factory<Checkpoint>
 */
final class CheckpointFactory extends Factory
{
    protected $model = Checkpoint::class;

    public function definition(): array
    {
        return [
            'id' => Uuid::v7(),
            'site_id' => Site::factory(),
            'name' => fake()->streetName(),
            'instruction' => null,
            'tag_type' => TagType::Nfc,
            'tag_uid' => strtoupper(bin2hex(random_bytes(7))),
            'qr_payload' => null,
            'lat_e7' => fake()->numberBetween(474_900_000, 475_100_000),
            'lng_e7' => fake()->numberBetween(190_300_000, 190_500_000),
            'accuracy_dm' => fake()->numberBetween(30, 200),
            'active' => true,
        ];
    }

    public function qr(): self
    {
        return $this->state(fn (array $attributes) => [
            'tag_type' => TagType::Qr,
            'tag_uid' => null,
            'qr_payload' => self::qrPayload($attributes['id']),
        ]);
    }

    /** waypost:cp:<checkpoint id>:<16 characters [A-Za-z0-9]> */
    public static function qrPayload(string $checkpointId): string
    {
        return 'waypost:cp:' . $checkpointId . ':' . Str::random(16);
    }
}
