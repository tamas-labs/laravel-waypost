<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Models\IdempotencyKey;
use Waypost\Models\User;
use Waypost\Support\Time;
use Waypost\Support\Uuid;

/** @extends Factory<IdempotencyKey> */
final class IdempotencyKeyFactory extends Factory
{
    protected $model = IdempotencyKey::class;

    public function definition(): array
    {
        $body = '{"name":"Telephely A"}';

        return [
            'owner_type' => User::class,
            'owner_id' => User::factory()->supervisor(),
            'key' => Uuid::v7(),
            'method' => 'POST',
            'path' => '/api/waypost/v1/sites',
            'request_hash' => hash('sha256', "POST\n/api/waypost/v1/sites\n\n{$body}"),
            'response_status' => 201,
            'response_body' => $body,
            'expires_at' => Time::now()->addDay(),
        ];
    }
}
