<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\DeviceDiagnosticFactory;
use Waypost\Enums\ErrorCode;

/**
 * What a device reported after the server rejected its chain entries (spec: Szinkron protokoll / Push és média).
 *
 * @property string $id
 * @property string $device_id
 * @property ErrorCode $code
 * @property string $entry_id
 * @property int $seq
 * @property int|null $expected_seq
 * @property int $server_last_seq
 * @property int $rejected_count
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 */
#[UseFactory(DeviceDiagnosticFactory::class)]
final class DeviceDiagnostic extends Model
{
    /** @use HasFactory<DeviceDiagnosticFactory> */
    use HasFactory;

    protected $table = 'device_diagnostics';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'code' => ErrorCode::class,
            'seq' => 'integer',
            'expected_seq' => 'integer',
            'server_last_seq' => 'integer',
            'rejected_count' => 'integer',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
