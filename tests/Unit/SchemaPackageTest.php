<?php

declare(strict_types=1);

use Waypost\Schema\Paths;

// waypost/schema comes from the path repository ../waypost-schema, a symlink to whatever is checked out there; the tag
// this package is built against is in .waypost-schema-version (CI checks out that tag).

/** @return array<string, mixed> */
function composerJson(): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('runs on ../waypost-schema checked out at the tag in .waypost-schema-version', function (): void {
    $pinned = trim((string) file_get_contents(dirname(__DIR__, 2) . '/.waypost-schema-version'));

    expect('v' . Paths::VERSION)->toBe($pinned);
});

it('states the pinned version in composer.json', function (): void {
    $composer = composerJson();
    [$major, $minor] = explode('.', Paths::VERSION);

    expect($composer['require']['waypost/schema'] ?? null)->toBe("^{$major}.{$minor}");
    expect($composer['repositories'][0]['options']['versions']['waypost/schema'] ?? null)->toBe(Paths::VERSION);
});
