<?php

declare(strict_types=1);

namespace Waypost\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Waypost\Models\Concerns\HasPrefixedTable;

/** Test-only model with an explicit table name and the prefix trait alone. */
final class WidgetTag extends Model
{
    use HasPrefixedTable;

    protected $table = 'widget_tag';
}
