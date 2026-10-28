<?php

declare(strict_types=1);

namespace Waypost\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Waypost\Enums\Role;
use Waypost\Models\User;
use Waypost\Pin\PinHasher;

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

    /** The PIN's verifier (PinHasher). */
    public function withPin(string $pin): self
    {
        return $this->state(fn () => app(PinHasher::class)->hash($pin)->attributes());
    }
}
