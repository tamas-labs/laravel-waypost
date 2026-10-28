<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\CheckpointFactory;
use Waypost\Enums\TagType;
use Waypost\Support\Table;

/**
 * A point of a site, identified by an NFC tag UID (uppercase hex) and/or a QR payload. The trained
 * location is integer-encoded: degrees × 10^7 and decimetres.
 *
 * @property string $id
 * @property string $site_id
 * @property string $name
 * @property string|null $instruction
 * @property TagType $tag_type
 * @property string|null $tag_uid
 * @property string|null $qr_payload
 * @property int|null $lat_e7
 * @property int|null $lng_e7
 * @property int|null $accuracy_dm
 * @property string|null $photo_media_id
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[UseFactory(CheckpointFactory::class)]
final class Checkpoint extends Model
{
    /** @use HasFactory<CheckpointFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['not_deleted'];

    protected $hidden = ['not_deleted'];

    protected function casts(): array
    {
        return [
            'tag_type' => TagType::class,
            'lat_e7' => 'integer',
            'lng_e7' => 'integer',
            'accuracy_dm' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'photo_media_id');
    }

    /** @return BelongsToMany<RouteVersion, $this, RouteVersionCheckpoint> */
    public function routeVersions(): BelongsToMany
    {
        return $this->belongsToMany(RouteVersion::class, Table::name('route_version_checkpoints'))
            ->using(RouteVersionCheckpoint::class)
            ->withPivot('position', 'walk_time_from_prev_s');
    }

    /** @return HasMany<Scan, $this> */
    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }
}
