<?php

declare(strict_types=1);

namespace Waypost\Tests\Fixtures;

use Waypost\Chain\EntryHash;
use Waypost\Models\Device;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Uuid;

/**
 * Writes a device's chain as the app does: consecutive seq, linked prev_hash, valid HMAC. Each method returns the signed
 * entry (chain/entry.json) for the push body; nothing is stored. The clock fields come from $fields, or from defaults
 * with no sync reference.
 */
final class ChainBuilder
{
    public int $seq = 0;

    public string $lastHash = EntryHash::GENESIS;

    public function __construct(public readonly Device $device)
    {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $fields  other fields of the entry than the payload
     * @return array<string, mixed>
     */
    public function entry(string $type, array $payload, array $fields = []): array
    {
        $this->seq++;
        $entry = [
            'id' => Uuid::v7(),
            'device_id' => $this->device->id,
            'seq' => $this->seq,
            'type' => $type,
            'payload' => $payload,
            'device_time' => '2026-10-27T20:00:00.000Z',
            'uptime_ms' => 1_000_000,
            'boot_id' => 'boot-1',
            'gnss_time' => null,
            'ref_server_time' => null,
            'ref_uptime_ms' => null,
            'ref_boot_id' => null,
            'prev_hash' => $this->lastHash,
            ...$fields,
        ];
        $entry['hash'] = EntryHash::of($entry, $this->device->secret);
        $this->lastHash = $entry['hash'];

        return $entry;
    }

    /**
     * A shift_started entry; the payload's shift_id is the shift's ID.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function shiftStarted(?User $guard = null, array $fields = []): array
    {
        return $this->entry('shift_started', [
            'shift_id' => Uuid::v7(),
            'guard_id' => ($guard ?? User::factory()->create())->id,
            'site_id' => $this->device->site_id ?? Site::factory()->create()->id,
        ], $fields);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function shiftClosed(string $shiftId, string $closedBy = 'guard', array $fields = []): array
    {
        return $this->entry('shift_closed', ['shift_id' => $shiftId, 'closed_by' => $closedBy], $fields);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function roundOpened(string $shiftId, string $routeVersionId, string $scheduleId, array $fields = []): array
    {
        return $this->entry('round_opened', [
            'round_id' => Uuid::v7(),
            'shift_id' => $shiftId,
            'route_version_id' => $routeVersionId,
            'schedule_id' => $scheduleId,
            'scheduled_at' => '2026-10-27T20:00:00.000Z',
            'time_limit_min' => 20,
        ], $fields);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function roundClosed(string $roundId, string $status = 'completed', array $fields = []): array
    {
        return $this->entry('round_closed', ['round_id' => $roundId, 'status' => $status, 'finished_at' => '2026-10-27T20:15:00.000Z'], $fields);
    }

    /**
     * An NFC scan of the checkpoint in the round; $payload overrides its fields.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function scan(?string $roundId, ?string $checkpointId, array $payload = [], array $fields = []): array
    {
        return $this->entry('scan', [
            'scan_id' => Uuid::v7(),
            'round_id' => $roundId,
            'checkpoint_id' => $checkpointId,
            'method' => 'nfc',
            'tag_uid' => '04A1B2C3D4E580',
            'qr_payload' => null,
            'ndef_raw' => null,
            'lat_e7' => null,
            'lng_e7' => null,
            'accuracy_dm' => null,
            'is_mock' => false,
            'flags' => $roundId === null ? ['out_of_round'] : [],
            'note' => null,
            'media' => [],
            ...$payload,
        ], $fields);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function incident(string $shiftId, array $payload = [], array $fields = []): array
    {
        return $this->entry('incident', [
            'incident_id' => Uuid::v7(),
            'shift_id' => $shiftId,
            'category' => 'open_door',
            'note' => 'Nyitva maradt az ajtó.',
            'lat_e7' => null,
            'lng_e7' => null,
            'accuracy_dm' => null,
            'nearest_checkpoint_id' => null,
            'scan_id' => null,
            'media' => [],
            ...$payload,
        ], $fields);
    }
}
