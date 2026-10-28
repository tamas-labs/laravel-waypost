<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Enums\Role;
use Waypost\Models\User;

/**
 * A guard without PIN by default (PBKDF2 with 100 000 iterations is slow; use withPin() when needed).
 *
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'role' => Role::Guard,
            'email' => null,
            'password' => null,
            'active' => true,
        ];
    }

    /** A supervisor who can log in with e-mail and the password "password". */
    public function supervisor(): self
    {
        return $this->state(fn () => [
            'role' => Role::Supervisor,
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['active' => false]);
    }

    /** PBKDF2-HMAC-SHA256 of the PIN with a random 16-byte salt and 100 000 iterations (spec: PIN-kód). */
    public function withPin(string $pin): self
    {
        return $this->state(function () use ($pin) {
            $salt = random_bytes(16);

            return [
                'pin_hash' => base64_encode(hash_pbkdf2('sha256', $pin, $salt, 100_000, 32, true)),
                'pin_salt' => base64_encode($salt),
                'pin_iterations' => 100_000,
            ];
        });
    }
}
