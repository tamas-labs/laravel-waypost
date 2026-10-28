<?php

declare(strict_types=1);

use Waypost\Schema\Paths;
use Waypost\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * A schema of waypost/schema, decoded, e.g. schema('common/uuid').
 *
 * @return array<string, mixed>
 */
function schema(string $name): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(Paths::schema($name)), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The "pattern" of a waypost/schema schema as a PCRE, e.g. schemaPattern('common/uuid'). The D modifier keeps
 * "$" from matching before a trailing newline, as in JSON Schema (and opis/json-schema).
 */
function schemaPattern(string $name): string
{
    $pattern = schema($name)['pattern'];
    assert(is_string($pattern));

    return '/' . $pattern . '/D';
}
