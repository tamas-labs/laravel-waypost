<?php

declare(strict_types=1);

use Waypost\Support\Uuid;
use Waypost\Tests\Fixtures\Widget;

use function Pest\Laravel\travel;

it('uses the pattern of the shared schema', function (): void {
    expect(Uuid::PATTERN)->toBe(schemaPattern('common/uuid'));
});

it('generates lowercase UUIDv7 values', function (): void {
    $ids = array_map(static fn (): string => Uuid::v7(), range(1, 100));

    expect($ids)->each->toMatch(Uuid::PATTERN)
        ->and(array_unique($ids))->toHaveCount(100);
});

it('generates time-ordered values', function (): void {
    $first = Uuid::v7();
    travel(1)->seconds();
    $second = Uuid::v7();

    expect(strcmp($first, $second))->toBeLessThan(0);
});

it('accepts only canonical UUIDv7 text', function (mixed $value, bool $valid): void {
    expect(Uuid::isV7($value))->toBe($valid);
})->with([
    'v7' => ['019a2a6e-5c1b-7c3d-9e4f-0123456789ab', true],
    'v7, variant b' => ['019a2a6e-5c1b-7c3d-bfff-0123456789ab', true],
    'uppercase' => ['019A2A6E-5C1B-7C3D-9E4F-0123456789AB', false],
    'v4' => ['8f14e45f-ceea-467a-9575-2f1b2c6d4a10', false],
    'wrong variant' => ['019a2a6e-5c1b-7c3d-cfff-0123456789ab', false],
    'no hyphens' => ['019a2a6e5c1b7c3d9e4f0123456789ab', false],
    'braces' => ['{019a2a6e-5c1b-7c3d-9e4f-0123456789ab}', false],
    'trailing newline' => ["019a2a6e-5c1b-7c3d-9e4f-0123456789ab\n", false],
    'empty' => ['', false],
    'not a string' => [123, false],
    'null' => [null, false],
]);

it('gives models a string UUIDv7 key', function (): void {
    $widget = new Widget();

    expect($widget->getKeyType())->toBe('string')
        ->and($widget->getIncrementing())->toBeFalse()
        ->and($widget->newUniqueId())->toMatch(Uuid::PATTERN)
        ->and($widget->uniqueIds())->toBe(['id']);
});
