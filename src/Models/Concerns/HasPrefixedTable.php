<?php

declare(strict_types=1);

namespace Waypost\Models\Concerns;

use Illuminate\Support\Str;
use Waypost\Support\Table;

/**
 * Puts the configured table prefix (Table::name()) in front of the model's table name: the explicit
 * $table, or the Eloquent default derived from the class name (User → waypost_users).
 *
 * The prefix is applied once, when the model is constructed, because Eloquent copies the resolved
 * name into new instances with setTable() (newInstance(), replicate()).
 */
trait HasPrefixedTable
{
    public function initializeHasPrefixedTable(): void
    {
        $this->table = Table::name($this->table ?? Str::snake(Str::pluralStudly(class_basename($this))));
    }
}
