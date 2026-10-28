<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Waypost\Casts\LocalTime;
use Waypost\Database\Factories\ScheduleFactory;
use Waypost\Enums\ScheduleMode;

/**
 * When the rounds of a route start, in the site's time zone: a list of HH:MM times, or every N minutes
 * from interval_from to interval_to (both included; to ≤ from crosses midnight). weekdays is a bit mask
 * of start days, 1 = Monday … 64 = Sunday.
 *
 * @property string $id
 * @property string $route_id
 * @property string $name
 * @property int $weekdays
 * @property ScheduleMode $mode
 * @property list<string>|null $start_times
 * @property string|null $interval_from
 * @property string|null $interval_to
 * @property int|null $interval_every_min
 * @property int|null $time_limit_min
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[UseFactory(ScheduleFactory::class)]
final class Schedule extends Model
{
    /** @use HasFactory<ScheduleFactory> */
    use HasFactory;
    use SoftDeletes;

    /** The database default, so a new model has it before it is read back. */
    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return [
            'weekdays' => 'integer',
            'mode' => ScheduleMode::class,
            'start_times' => 'array',
            'interval_from' => LocalTime::class,
            'interval_to' => LocalTime::class,
            'interval_every_min' => 'integer',
            'time_limit_min' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Route, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /** @return HasMany<Round, $this> */
    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }
}
