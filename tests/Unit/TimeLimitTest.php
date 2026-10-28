<?php

declare(strict_types=1);

use Waypost\Schedule\TimeLimit;
use Waypost\Schema\Paths;

/** @return list<array{walk_duration_s: int|null, time_limit_min: int}> */
function timeLimitVectors(): array
{
    /** @var array{time_limit_default: list<array{walk_duration_s: int|null, time_limit_min: int}>} $vectors */
    $vectors = json_decode((string) file_get_contents(Paths::scheduleVectors()), true, flags: JSON_THROW_ON_ERROR);

    return $vectors['time_limit_default'];
}

it('computes the default time limit of schedule/test-vectors.json', function (): void {
    $vectors = timeLimitVectors();
    expect($vectors)->not->toBeEmpty();

    foreach ($vectors as ['walk_duration_s' => $walk, 'time_limit_min' => $expected]) {
        expect(TimeLimit::default($walk))->toBe($expected, 'walk_duration_s = ' . var_export($walk, true));
    }
});
