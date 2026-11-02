<?php

declare(strict_types=1);

namespace Waypost\Chain;

use InvalidArgumentException;
use JsonException;

/**
 * The hash of a chain entry: lowercase hex of HMAC-SHA256(key = device secret, message = canonical JSON of the entry
 * without its "hash" property). chain/test-vectors.json of waypost/schema is the reference.
 */
final class EntryHash
{
    /** prev_hash of the first entry of a chain. */
    public const string GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private function __construct()
    {
    }

    /**
     * @param  array<string, mixed>  $entry  chain/entry.json, with or without hash
     * @param  string  $secret  the device secret in base64
     */
    public static function of(array $entry, string $secret): string
    {
        unset($entry['hash']);

        return hash_hmac('sha256', CanonicalJson::encode($entry), (string) base64_decode($secret, true));
    }

    /**
     * Whether the entry's hash is right; data that cannot be hashed never is.
     *
     * @param  array<string, mixed>  $entry  chain/entry.json
     */
    public static function matches(array $entry, string $secret): bool
    {
        try {
            return is_string($entry['hash'] ?? null) && hash_equals(self::of($entry, $secret), $entry['hash']);
        } catch (InvalidArgumentException|JsonException) {
            return false;
        }
    }
}
