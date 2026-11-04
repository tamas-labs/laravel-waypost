<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\NoDataAlertFactory;

/**
 * A "Nincs adat" alert sent for a schedule window of a site (spec: „Nincs adat” figyelmeztetés): the expected round
 * whose deadline passed long enough ago with the phone silent since.
 *
 * @property string $id
 * @property string $site_id
 * @property string $window_day  the local start day (Y-m-d) of the window
 * @property Carbon $scheduled_at
 * @property Carbon $deadline
 * @property Carbon|null $last_seen_at  the phone's last sync then
 * @property list<string> $recipients
 * @property Carbon|null $sent_at
 */
#[UseFactory(NoDataAlertFactory::class)]
final class NoDataAlert extends Model
{
    /** @use HasFactory<NoDataAlertFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'deadline' => 'datetime',
            'last_seen_at' => 'datetime',
            'recipients' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
