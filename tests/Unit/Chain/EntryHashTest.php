<?php

declare(strict_types=1);

use Waypost\Chain\EntryHash;

it('computes the hash of every test vector', function (): void {
    $vectors = chainVectors();

    foreach ($vectors['cases'] as $case) {
        expect(EntryHash::of($case['input'], $vectors['key_base64']))->toBe($case['hash'], $case['name']);
    }
});

it('verifies the chain of the test vectors, each entry linked to the previous one', function (): void {
    $vectors = chainVectors();
    $previous = EntryHash::GENESIS;

    expect($vectors['chain']['entries'])->toHaveCount(3);
    foreach ($vectors['chain']['entries'] as $vector) {
        /** @var array{input?: array<string, mixed>, hash: string} $vector */
        $entry = ($vector['input'] ?? $vector) + ['hash' => $vector['hash']];

        expect($entry['prev_hash'])->toBe($previous)
            ->and(EntryHash::matches($entry, $vectors['key_base64']))->toBeTrue();
        $previous = $vector['hash'];
    }
});

it('ignores the hash field itself', function (): void {
    $vectors = chainVectors();
    $case = $vectors['cases'][0];

    expect(EntryHash::of(['hash' => $case['hash']] + $case['input'], $vectors['key_base64']))->toBe($case['hash']);
});

it('notices a changed field, a wrong key and data that cannot be hashed', function (): void {
    $vectors = chainVectors();
    $case = $vectors['cases'][0];
    $entry = $case['input'] + ['hash' => $case['hash']];

    expect(EntryHash::matches($entry, $vectors['key_base64']))->toBeTrue()
        ->and(EntryHash::matches(['seq' => 2] + $entry, $vectors['key_base64']))->toBeFalse()
        ->and(EntryHash::matches($entry, base64_encode(str_repeat("\x01", 32))))->toBeFalse()
        ->and(EntryHash::matches(['uptime_ms' => 1.5] + $entry, $vectors['key_base64']))->toBeFalse()
        ->and(EntryHash::matches($case['input'], $vectors['key_base64']))->toBeFalse();
});
