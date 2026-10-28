<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Waypost\Models\Concerns\HasPrefixedTable;
use Waypost\Models\Concerns\HasUuidV7PrimaryKey;
use Waypost\Models\Concerns\StoresUtcMilliseconds;

/**
 * Base of the package's models: prefixed table, UUIDv7 primary key, UTC timestamps with milliseconds.
 * Models that cannot extend it (pivots without an id, Sanctum's token model) use the traits.
 *
 * Mass assignment is open: request bodies reach the models only after the waypost/schema validation,
 * whose schemas reject unknown properties.
 */
abstract class Model extends EloquentModel
{
    use HasPrefixedTable;
    use HasUuidV7PrimaryKey;
    use StoresUtcMilliseconds;

    protected $guarded = [];
}
