<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\RoundFactory;
use Waypost\Enums\RoundStatus;

/**
 * Projection of round_opened / round_closed entries (and of the first scan: started_at).
 *
 * @property string $id
 * @property string $shift_id
 * @property string $route_version_id
 * @property string $schedule_id
 * @property Carbon $scheduled_at
 * @property int|null $time_limit_min
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property RoundStatus $status
 */
#[UseFactory(RoundFactory::class)]
final class Round extends Model
{
    /** @use HasFactory<RoundFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'time_limit_min' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'status' => RoundStatus::class,
        ];
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<RouteVersion, $this> */
    public function routeVersion(): BelongsTo
    {
        return $this->belongsTo(RouteVersion::class);
    }

    /** @return BelongsTo<Schedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /** @return HasMany<Scan, $this> */
    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }
}
