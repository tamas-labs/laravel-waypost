<?php

declare(strict_types=1);

namespace Waypost\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;
use Waypost\Models\Model;

/**
 * Test-only model on the base Model; its table is created by the tests (widgetTable()).
 *
 * @property string $id
 * @property \Illuminate\Support\Carbon|null $happened_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
final class Widget extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['happened_at' => 'datetime'];
    }
}
