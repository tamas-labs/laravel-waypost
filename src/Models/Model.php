<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Waypost\Models\Concerns\HasPrefixedTable;
use Waypost\Models\Concerns\HasUuidV7PrimaryKey;
use Waypost\Models\Concerns\StoresUtcMilliseconds;

/**
 * Base of the package's models: prefixed table, UUIDv7 primary key, UTC timestamps with milliseconds.
 * Models that cannot extend it (authenticatable User and Device, pivots without an id) use the traits.
 */
abstract class Model extends EloquentModel
{
    use HasPrefixedTable;
    use HasUuidV7PrimaryKey;
    use StoresUtcMilliseconds;
}
