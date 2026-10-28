<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\MediaFactory;

/**
 * A photo of a scan, an incident or a checkpoint. The row exists as soon as something refers to it
 * (with the expected sha256); mime, size_bytes, path and uploaded_at are filled by the upload.
 *
 * @property string $id
 * @property string|null $device_id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $sha256
 * @property string|null $mime
 * @property int|null $size_bytes
 * @property string|null $path
 * @property Carbon $taken_at
 * @property Carbon|null $uploaded_at
 */
#[UseFactory(MediaFactory::class)]
final class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    protected $table = 'media';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'taken_at' => 'datetime',
            'uploaded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return MorphTo<\Illuminate\Database\Eloquent\Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
