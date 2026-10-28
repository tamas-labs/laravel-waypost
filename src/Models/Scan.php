<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\ScanFactory;
use Waypost\Enums\ScanMethod;

/**
 * Projection of a scan entry. round_id is null outside a round, checkpoint_id for an unknown tag.
 *
 * @property string $id
 * @property string|null $round_id
 * @property string|null $checkpoint_id
 * @property ScanMethod $method
 * @property Carbon $scanned_at
 * @property int|null $lat_e7
 * @property int|null $lng_e7
 * @property int|null $accuracy_dm
 * @property bool $is_mock
 * @property list<string> $flags
 * @property string|null $note
 * @property string $chain_entry_id
 */
#[UseFactory(ScanFactory::class)]
final class Scan extends Model
{
    /** @use HasFactory<ScanFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'method' => ScanMethod::class,
            'scanned_at' => 'datetime',
            'lat_e7' => 'integer',
            'lng_e7' => 'integer',
            'accuracy_dm' => 'integer',
            'is_mock' => 'boolean',
            'flags' => 'array',
        ];
    }

    /** @return BelongsTo<Round, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    /** @return BelongsTo<Checkpoint, $this> */
    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(Checkpoint::class);
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
