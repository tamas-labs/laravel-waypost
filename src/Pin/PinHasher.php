<?php

declare(strict_types=1);

namespace Waypost\Pin;

use InvalidArgumentException;
use Random\Randomizer;

/**
 * The PIN verifier (spec: Azonosítás / PIN-kód): PBKDF2-HMAC-SHA256 over the 6 ASCII digits with a random 16-byte
 * salt and 100 000 iterations, 32 bytes, base64. The site phone computes the same offline; both sides are tested
 * against waypost/schema's pin/test-vectors.json.
 */
final readonly class PinHasher
{
    /** A PIN: exactly 6 ASCII digits (pin/test-vectors.json: pin_pattern). */
    public const string PATTERN = '/^[0-9]{6}$/D';

    /** Iterations of a newly set PIN; checking uses the stored count, so it can be raised later. */
    public const int ITERATIONS = 100_000;

    public const int SALT_BYTES = 16;

    public const int HASH_BYTES = 32;

    public function __construct(private Randomizer $randomizer = new Randomizer())
    {
    }

    public static function isWellFormed(string $pin): bool
    {
        return preg_match(self::PATTERN, $pin) === 1;
    }

    /**
     * The verifier of the PIN with a fresh salt.
     *
     * @throws InvalidArgumentException for a malformed PIN
     */
    public function hash(string $pin): PinHash
    {
        $salt = $this->randomizer->getBytes(self::SALT_BYTES);

        return new PinHash(base64_encode($this->derive($pin, $salt, self::ITERATIONS)), base64_encode($salt), self::ITERATIONS);
    }

    /** Whether the PIN matches the verifier; false for a malformed PIN or a damaged verifier. */
    public function check(string $pin, PinHash $hash): bool
    {
        $salt = base64_decode($hash->salt, true);
        if (!self::isWellFormed($pin) || $salt === false || $hash->iterations < 1) {
            return false;
        }

        return hash_equals($hash->hash, base64_encode($this->derive($pin, $salt, $hash->iterations)));
    }

    /** A random PIN; every one of the 1 000 000 is equally likely, leading zeros included. */
    public function generate(): string
    {
        return sprintf('%06d', $this->randomizer->getInt(0, 999_999));
    }

    /** @param  int<1, max>  $iterations */
    private function derive(string $pin, string $salt, int $iterations): string
    {
        if (!self::isWellFormed($pin)) {
            throw new InvalidArgumentException('A PIN is exactly 6 digits.');
        }

        return hash_pbkdf2('sha256', $pin, $salt, $iterations, self::HASH_BYTES, true);
    }
}
