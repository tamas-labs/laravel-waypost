<?php

declare(strict_types=1);

use Random\Engine;
use Random\Randomizer;
use Waypost\Pin\PinHash;
use Waypost\Pin\PinHasher;
use Waypost\Schema\Paths;

/**
 * waypost/schema's pin/test-vectors.json, shared with the app.
 *
 * @return array{pin_pattern: string, defaults: array{iterations: int, salt_bytes: int, hash_bytes: int}, cases: list<array{name: string, pin: string, pin_salt: string, pin_iterations: int, pin_hash: string}>, mismatches: list<array{name: string, pin: string, pin_salt: string, pin_iterations: int, pin_hash: string}>, malformed_pins: list<string>}
 */
function pinVectors(): array
{
    /** @var array{pin_pattern: string, defaults: array{iterations: int, salt_bytes: int, hash_bytes: int}, cases: list<array{name: string, pin: string, pin_salt: string, pin_iterations: int, pin_hash: string}>, mismatches: list<array{name: string, pin: string, pin_salt: string, pin_iterations: int, pin_hash: string}>, malformed_pins: list<string>} */
    return json_decode((string) file_get_contents(Paths::pinVectors()), true, flags: JSON_THROW_ON_ERROR);
}

/** @param  array{pin_salt: string, pin_iterations: int, pin_hash: string}  $vector */
function vectorHash(array $vector): PinHash
{
    return new PinHash($vector['pin_hash'], $vector['pin_salt'], $vector['pin_iterations']);
}

it('uses the parameters and the PIN pattern of the shared vectors', function (): void {
    $vectors = pinVectors();

    expect(PinHasher::PATTERN)->toBe('/' . $vectors['pin_pattern'] . '/D')
        ->and([PinHasher::ITERATIONS, PinHasher::SALT_BYTES, PinHasher::HASH_BYTES])
        ->toBe([$vectors['defaults']['iterations'], $vectors['defaults']['salt_bytes'], $vectors['defaults']['hash_bytes']]);
});

it('accepts every matching vector and rejects the mismatches', function (): void {
    $hasher = new PinHasher();
    $vectors = pinVectors();

    foreach ($vectors['cases'] as $vector) {
        expect($hasher->check($vector['pin'], vectorHash($vector)))->toBeTrue($vector['name']);
    }
    foreach ($vectors['mismatches'] as $vector) {
        expect($hasher->check($vector['pin'], vectorHash($vector)))->toBeFalse($vector['name']);
    }
});

it('refuses to hash a malformed PIN and never matches one', function (): void {
    $hasher = new PinHasher();
    $basic = vectorHash(pinVectors()['cases'][0]);

    foreach (pinVectors()['malformed_pins'] as $pin) {
        expect(PinHasher::isWellFormed($pin))->toBeFalse(json_encode($pin, JSON_THROW_ON_ERROR))
            ->and($hasher->check($pin, $basic))->toBeFalse()
            ->and(fn () => $hasher->hash($pin))->toThrow(InvalidArgumentException::class);
    }
});

it('hashes with a fresh salt and the default iterations', function (): void {
    $hasher = new PinHasher();

    $first = $hasher->hash('123456');
    $second = $hasher->hash('123456');

    expect(strlen((string) base64_decode($first->salt, true)))->toBe(16)
        ->and(strlen((string) base64_decode($first->hash, true)))->toBe(32)
        ->and($first->iterations)->toBe(100_000)
        ->and($first->salt)->not->toBe($second->salt)
        ->and($first->hash)->not->toBe($second->hash)
        ->and($hasher->check('123456', $first))->toBeTrue()
        ->and($hasher->check('123457', $first))->toBeFalse();
});

it('does not match a damaged verifier', function (): void {
    $hasher = new PinHasher();
    $hash = $hasher->hash('123456');

    expect($hasher->check('123456', new PinHash($hash->hash, '%%%', $hash->iterations)))->toBeFalse()
        ->and($hasher->check('123456', new PinHash($hash->hash, $hash->salt, 0)))->toBeFalse()
        ->and($hasher->check('123456', new PinHash('', $hash->salt, $hash->iterations)))->toBeFalse();
});

it('generates 6-digit PINs, leading zeros included', function (): void {
    $zeros = new class implements Engine {
        public function generate(): string
        {
            return "\0\0\0\0\0\0\0\0";
        }
    };

    expect((new PinHasher(new Randomizer($zeros)))->generate())->toBe('000000');

    $hasher = new PinHasher();
    foreach (range(1, 50) as $ignored) {
        expect(PinHasher::isWellFormed($hasher->generate()))->toBeTrue();
    }
});
