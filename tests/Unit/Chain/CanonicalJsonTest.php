<?php

declare(strict_types=1);

use Waypost\Chain\CanonicalJson;

it('writes the canonical JSON of every test vector', function (): void {
    $cases = chainVectors()['cases'];

    expect($cases)->not->toBeEmpty();
    foreach ($cases as $case) {
        expect(CanonicalJson::encode($case['input']))->toBe($case['canonical_json'], $case['name']);
    }
});

it('sorts the keys at every level and keeps the order of lists', function (): void {
    expect(CanonicalJson::encode(['b' => [3, 1, 2], 'a' => ['y' => null, 'x' => true]]))->toBe('{"a":{"x":true,"y":null},"b":[3,1,2]}');
});

it('writes strings as JSON.stringify does', function (): void {
    expect(CanonicalJson::encode("árvíz/\u{2028}\"\\\n\x01"))->toBe('"árvíz/' . "\u{2028}" . '\"\\\\\n\u0001"');
});

it('takes an integer written as a float for the integer', function (): void {
    expect(CanonicalJson::encode(['seq' => 412.0, 'zero' => -0.0]))->toBe('{"seq":412,"zero":0}');
});

it('refuses what hashed data may not contain', function (mixed $value): void {
    expect(fn () => CanonicalJson::encode($value))->toThrow(InvalidArgumentException::class);
})->with([
    'floating-point number' => [47.4979],
    'nested floating-point number' => [['lat' => [47.4979]]],
    'infinity' => [INF],
    'not a number' => [NAN],
    'float over 2^53' => [9007199254740992.0],
    'integer over 2^53' => [9007199254740992],
    'integer under -2^53' => [-9007199254740992],
    'object' => [new stdClass()],
]);

it('takes the largest safe integers', function (): void {
    expect(CanonicalJson::encode([9007199254740991, -9007199254740991]))->toBe('[9007199254740991,-9007199254740991]');
});

it('refuses a string that is not UTF-8', function (): void {
    expect(fn () => CanonicalJson::encode("\xC3\x28"))->toThrow(JsonException::class);
});
