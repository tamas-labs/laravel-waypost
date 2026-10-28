<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\ChainEntryFactory;
use Waypost\Enums\ChainEntryType;
use Waypost\Enums\VerifyStatus;

/**
 * An entry of a device's chained log, as received (chain/entry.json) plus the server's verification.
 * The single source of truth of what happened on the device; shifts, rounds, scans and incidents are
 * projections of it.
 *
 * @property string $id
 * @property string $device_id
 * @property int $seq
 * @property ChainEntryType $type
 * @property array<string, mixed> $payload
 * @property Carbon $device_time
 * @property int $uptime_ms
 * @property string $boot_id
 * @property Carbon|null $gnss_time
 * @property Carbon|null $ref_server_time
 * @property int|null $ref_uptime_ms
 * @property string|null $ref_boot_id
 * @property string $prev_hash
 * @property string $hash
 * @property Carbon $received_at
 * @property VerifyStatus $verify_status
 * @property list<string> $flags
 */
#[UseFactory(ChainEntryFactory::class)]
final class ChainEntry extends Model
{
    /** @use HasFactory<ChainEntryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'type' => ChainEntryType::class,
            'payload' => 'array',
            'device_time' => 'datetime',
            'uptime_ms' => 'integer',
            'gnss_time' => 'datetime',
            'ref_server_time' => 'datetime',
            'ref_uptime_ms' => 'integer',
            'received_at' => 'datetime',
            'verify_status' => VerifyStatus::class,
            'flags' => 'array',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
