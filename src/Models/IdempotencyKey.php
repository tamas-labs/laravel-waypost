<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\IdempotencyKeyFactory;

/**
 * The Idempotency-Key of a write request of a caller (the user or device owning the token), with the
 * request fingerprint and the stored response. response_status is null while the first request runs.
 *
 * @property string $id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $key
 * @property string $method
 * @property string $path
 * @property string $request_hash
 * @property int|null $response_status
 * @property string|null $response_body
 * @property Carbon|null $created_at
 * @property Carbon $expires_at
 */
#[UseFactory(IdempotencyKeyFactory::class)]
final class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            // A response may carry a secret shown only once (a generated PIN, a device token).
            'response_body' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    /** @return MorphTo<\Illuminate\Database\Eloquent\Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
