<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\IncidentFactory;
use Waypost\Enums\IncidentCategory;

/**
 * Projection of an incident entry. scan_id links the incident of a manual scan (damaged_tag).
 *
 * @property string $id
 * @property string $shift_id
 * @property IncidentCategory $category
 * @property string|null $note
 * @property Carbon $occurred_at
 * @property int|null $lat_e7
 * @property int|null $lng_e7
 * @property int|null $accuracy_dm
 * @property string|null $nearest_checkpoint_id
 * @property string|null $scan_id
 * @property string $chain_entry_id
 */
#[UseFactory(IncidentFactory::class)]
final class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'category' => IncidentCategory::class,
            'occurred_at' => 'datetime',
            'lat_e7' => 'integer',
            'lng_e7' => 'integer',
            'accuracy_dm' => 'integer',
        ];
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<Checkpoint, $this> */
    public function nearestCheckpoint(): BelongsTo
    {
        return $this->belongsTo(Checkpoint::class, 'nearest_checkpoint_id');
    }

    /** @return BelongsTo<Scan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    /** @return BelongsTo<ChainEntry, $this> */
    public function chainEntry(): BelongsTo
    {
        return $this->belongsTo(ChainEntry::class);
    }

    /** @return MorphMany<Media, $this> */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'owner');
    }
}
