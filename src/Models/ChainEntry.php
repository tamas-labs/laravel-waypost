<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\ChainEntryFactory;
use Waypost\Enums\ChainEntryType;
use Waypost\Enums\ChainFlag;
use Waypost\Enums\VerifyStatus;
use Waypost\Support\Time;

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
 * @property list<string> $flags  what the server set (ChainFlag); the app's own flags are in the payload
 * @property Carbon|null $projected_at  null: rejected, or it refers to something not on the server yet
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
            'projected_at' => 'datetime',
        ];
    }

    /**
     * A received entry, not saved yet.
     *
     * @param  array<string, mixed>  $data  chain/entry.json
     */
    public static function fromPush(array $data): self
    {
        $time = fn (?string $value) => $value === null ? null : Time::fromIso($value);

        return new self([
            ...$data,
            'device_time' => Time::fromIso($data['device_time']),
            'gnss_time' => $time($data['gnss_time']),
            'ref_server_time' => $time($data['ref_server_time']),
        ]);
    }

    /**
     * The entry as the device sent and signed it: chain/entry.json.
     *
     * @return array<string, mixed>
     */
    public function content(): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'seq' => $this->seq,
            'type' => $this->type->value,
            'payload' => $this->payload,
            'device_time' => Time::iso($this->device_time),
            'uptime_ms' => $this->uptime_ms,
            'boot_id' => $this->boot_id,
            'gnss_time' => $this->gnss_time === null ? null : Time::iso($this->gnss_time),
            'ref_server_time' => $this->ref_server_time === null ? null : Time::iso($this->ref_server_time),
            'ref_uptime_ms' => $this->ref_uptime_ms,
            'ref_boot_id' => $this->ref_boot_id,
            'prev_hash' => $this->prev_hash,
            'hash' => $this->hash,
        ];
    }

    public function addFlag(ChainFlag $flag): void
    {
        if (!in_array($flag->value, $this->flags, true)) {
            $this->update(['flags' => [...$this->flags, $flag->value]]);
        }
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
