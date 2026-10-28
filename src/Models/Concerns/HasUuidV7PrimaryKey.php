<?php

declare(strict_types=1);

namespace Waypost\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Waypost\Support\Uuid;

/**
 * UUIDv7 string primary key, generated on create unless already set (devices and the app's outbox
 * create IDs offline). Route model binding accepts only the canonical UUIDv7 form; anything else is a
 * 404 without a query.
 */
trait HasUuidV7PrimaryKey
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return Uuid::v7();
    }

    /** @param  mixed  $value */
    protected function isValidUniqueId($value): bool
    {
        return Uuid::isV7($value);
    }
}
