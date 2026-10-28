<?php

declare(strict_types=1);

namespace Waypost\Pin;

use Waypost\Models\User;

/** A PIN verifier as stored on the user and sent to the site phone (pin_hash, pin_salt, pin_iterations). */
final readonly class PinHash
{
    /**
     * @param  string  $hash  PBKDF2-HMAC-SHA256 output, base64
     * @param  string  $salt  random salt, base64
     */
    public function __construct(
        public string $hash,
        public string $salt,
        public int $iterations,
    ) {
    }

    /** The user's verifier, or null if the user has no PIN. */
    public static function of(User $user): ?self
    {
        if ($user->pin_hash === null || $user->pin_salt === null || $user->pin_iterations === null) {
            return null;
        }

        return new self($user->pin_hash, $user->pin_salt, $user->pin_iterations);
    }

    /** @return array{pin_hash: string, pin_salt: string, pin_iterations: int} */
    public function attributes(): array
    {
        return ['pin_hash' => $this->hash, 'pin_salt' => $this->salt, 'pin_iterations' => $this->iterations];
    }
}
