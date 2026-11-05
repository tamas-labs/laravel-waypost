<?php

declare(strict_types=1);

namespace Waypost\Chain;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Waypost\Enums\ChainEntryType;
use Waypost\Enums\ChainFlag;
use Waypost\Enums\MediaOwnerType;
use Waypost\Enums\RoundStatus;
use Waypost\Jobs\SendShiftReport;
use Waypost\Models\ChainEntry;
use Waypost\Models\Checkpoint;
use Waypost\Models\Device;
use Waypost\Models\Incident;
use Waypost\Models\Media;
use Waypost\Models\Round;
use Waypost\Models\RouteVersion;
use Waypost\Models\RouteVersionCheckpoint;
use Waypost\Models\Scan;
use Waypost\Models\Schedule;
use Waypost\Models\Shift;
use Waypost\Models\User;
use Waypost\Support\Table;
use Waypost\Support\Time;

/**
 * Applies a verified chain entry to the projections (shifts, rounds, scans, incidents, media), idempotently by the IDs
 * of its payload; the server's scan flags are decided here, because they need the projections. A shift's close
 * dispatches its report (SendShiftReport), after the push's transaction.
 *
 * An entry may refer to something that is not on the server yet: the phone pushes before its outbox (a checkpoint
 * trained offline). apply() then answers false and writes nothing but the media rows, and the entry is applied by a
 * later push. Entries are therefore applied independently of each other, in whatever order they become applicable.
 *
 * Whatever an entry refers to is the device's own: its shifts and rounds, and the route versions, schedules, checkpoints
 * and scans of its site. Anything else counts as unknown, so the entry is never applied (projected_at stays null, and
 * the report says the data is incomplete): a phone's chain cannot bring another site's names into its site's report.
 */
final class Projector
{
    /** @return bool whether the entry is applied; false: it refers to something unknown */
    public function apply(Device $device, ChainEntry $entry): bool
    {
        $this->recordMedia($device, $entry);

        // Exhaustive on purpose, without a default: a new entry type without its projection fails PHPStan
        // (match.unhandled) instead of being stored silently unprojected.
        return match ($entry->type) {
            ChainEntryType::ShiftStarted => $this->shiftStarted($device, $entry),
            ChainEntryType::ShiftClosed => $this->shiftClosed($device, $entry),
            ChainEntryType::RoundOpened => $this->roundOpened($device, $entry),
            ChainEntryType::Scan => $this->scan($device, $entry),
            ChainEntryType::Incident => $this->incident($device, $entry),
            ChainEntryType::RoundClosed => $this->roundClosed($device, $entry),
        };
    }

    /**
     * The placeholder rows of the photos an entry cites, whether or not the entry itself can be applied yet: the phone
     * uploads them right after the push. The first entry citing a photo owns it (a manual entry's scan; its incident
     * cites the same photos); taken_at is the entry's until the upload brings the exact value.
     */
    private function recordMedia(Device $device, ChainEntry $entry): void
    {
        [$owner, $ownerKey] = match ($entry->type) {
            ChainEntryType::Scan => [MediaOwnerType::Scan->stored(), 'scan_id'],
            ChainEntryType::Incident => [MediaOwnerType::Incident->stored(), 'incident_id'],
            default => [null, null],
        };
        if ($owner === null) {
            return;
        }

        foreach ($entry->payload['media'] as $photo) {
            Media::query()->firstOrCreate(['id' => $photo['id']], [
                'device_id' => $device->id,
                'owner_type' => $owner,
                'owner_id' => $entry->payload[$ownerKey],
                'sha256' => $photo['sha256'],
                // Not after the entry arrived: a phone clock ahead must not keep the photo past its retention.
                'taken_at' => $entry->device_time->isAfter($entry->received_at) ? $entry->received_at : $entry->device_time,
            ]);
        }
    }

    private function shiftStarted(Device $device, ChainEntry $entry): bool
    {
        $payload = $entry->payload;
        if (Shift::query()->whereKey($payload['shift_id'])->exists()) {
            return true;
        }
        if ($payload['site_id'] !== $device->site_id || !User::withTrashed()->whereKey($payload['guard_id'])->exists()) {
            return false;
        }

        Shift::query()->create([
            'id' => $payload['shift_id'],
            'site_id' => $payload['site_id'],
            'device_id' => $device->id,
            'guard_id' => $payload['guard_id'],
            'started_at' => TrustedTime::of($entry),
            'chain_entry_id' => $entry->id,
        ]);

        return true;
    }

    private function shiftClosed(Device $device, ChainEntry $entry): bool
    {
        $shift = $this->shift($device, $entry->payload['shift_id']);
        if ($shift === null) {
            return false;
        }
        if ($shift->ended_at === null) {
            $shift->update(['ended_at' => TrustedTime::of($entry), 'closed_by' => $entry->payload['closed_by']]);
            $this->flagLateEntries($device, $shift, $entry);
            SendShiftReport::after($shift);
        }

        return true;
    }

    /**
     * late_sync on the entries of a shift just closed. Only now is the close known: every entry of the shift has a lower
     * seq than its shift_closed, and the chain has one open shift at a time, so they are the seq range of the shift.
     */
    private function flagLateEntries(Device $device, Shift $shift, ChainEntry $closing): void
    {
        $from = ChainEntry::query()->whereKey($shift->chain_entry_id)->value('seq');
        $closedAt = $shift->ended_at ?? TrustedTime::of($closing);
        ChainEntry::query()
            ->where('device_id', $device->id)
            ->whereBetween('seq', [$from ?? 1, $closing->seq])
            ->get()
            ->filter(fn (ChainEntry $entry): bool => ServerFlags::lateSync($entry->received_at, $closedAt))
            ->each(function (ChainEntry $entry): void {
                $entry->addFlag(ChainFlag::LateSync);
                $scan = Scan::query()->where('chain_entry_id', $entry->id)->first();
                $scan?->update(['flags' => array_values(array_unique([...$scan->flags, ChainFlag::LateSync->value]))]);
            });
    }

    private function roundOpened(Device $device, ChainEntry $entry): bool
    {
        $payload = $entry->payload;
        if (Round::query()->whereKey($payload['round_id'])->exists()) {
            return true;
        }
        $version = $this->routeVersion($device, $payload['route_version_id']);
        if ($this->shift($device, $payload['shift_id']) === null
            || $version === null
            || !Schedule::withTrashed()->whereKey($payload['schedule_id'])->where('route_id', $version->route_id)->exists()) {
            return false;
        }

        Round::query()->create([
            'id' => $payload['round_id'],
            'shift_id' => $payload['shift_id'],
            'route_version_id' => $payload['route_version_id'],
            'schedule_id' => $payload['schedule_id'],
            'scheduled_at' => Time::fromIso($payload['scheduled_at']),
            'time_limit_min' => $payload['time_limit_min'],
            'status' => RoundStatus::Open,
        ]);

        return true;
    }

    private function roundClosed(Device $device, ChainEntry $entry): bool
    {
        $round = $this->round($device, $entry->payload['round_id']);
        if ($round === null) {
            return false;
        }
        if ($round->finished_at === null) {
            $round->update(['status' => $entry->payload['status'], 'finished_at' => Time::fromIso($entry->payload['finished_at'])]);
        }

        return true;
    }

    private function scan(Device $device, ChainEntry $entry): bool
    {
        $payload = $entry->payload;
        if (Scan::query()->whereKey($payload['scan_id'])->exists()) {
            return true;
        }
        $round = $payload['round_id'] === null ? null : $this->round($device, $payload['round_id']);
        $checkpoint = $payload['checkpoint_id'] === null ? null : $this->checkpoint($device, $payload['checkpoint_id'])->first();
        if (($payload['round_id'] !== null && $round === null) || ($payload['checkpoint_id'] !== null && $checkpoint === null)) {
            return false;
        }

        $scannedAt = TrustedTime::of($entry);
        if ($checkpoint !== null) {
            if (ServerFlags::farFromCheckpoint($payload['lat_e7'], $payload['lng_e7'], $payload['accuracy_dm'], $checkpoint)) {
                $entry->addFlag(ChainFlag::FarFromCheckpoint);
            }
            if ($round !== null) {
                if ($this->isTooFast($round, $checkpoint, $entry, $scannedAt)) {
                    $entry->addFlag(ChainFlag::TooFast);
                }
                $this->startRound($round, $scannedAt);
            }
        }

        Scan::query()->create([
            'id' => $payload['scan_id'],
            'round_id' => $payload['round_id'],
            'checkpoint_id' => $payload['checkpoint_id'],
            'method' => $payload['method'],
            'scanned_at' => $scannedAt,
            'lat_e7' => $payload['lat_e7'],
            'lng_e7' => $payload['lng_e7'],
            'accuracy_dm' => $payload['accuracy_dm'],
            'is_mock' => $payload['is_mock'],
            'flags' => array_values(array_unique([...$payload['flags'], ...$entry->flags])),
            'note' => $payload['note'],
            'chain_entry_id' => $entry->id,
        ]);

        return true;
    }

    /** The earliest scan of a checkpoint starts the round; an unknown tag does not (spec: Kör / Folyamatban). */
    private function startRound(Round $round, DateTimeInterface $scannedAt): void
    {
        if ($round->started_at === null || Time::milliseconds($scannedAt) < Time::milliseconds($round->started_at)) {
            $round->fill(['started_at' => $scannedAt]);
        }
        if ($round->status === RoundStatus::Open) {
            $round->status = RoundStatus::InProgress;
        }
        $round->save();
    }

    /** Compared with the round's previous scan of a checkpoint in the chain, if the two are neighbours in the training. */
    private function isTooFast(Round $round, Checkpoint $checkpoint, ChainEntry $entry, DateTimeInterface $scannedAt): bool
    {
        $scans = Table::name('scans');
        $entries = Table::name('chain_entries');
        $previous = Scan::query()
            ->select("{$scans}.*")
            ->join($entries, "{$entries}.id", '=', "{$scans}.chain_entry_id")
            ->where("{$scans}.round_id", $round->id)
            ->whereNotNull("{$scans}.checkpoint_id")
            ->where("{$entries}.seq", '<', $entry->seq)
            ->orderByDesc("{$entries}.seq")
            ->first();
        if ($previous === null) {
            return false;
        }

        $points = RouteVersionCheckpoint::query()
            ->where('route_version_id', $round->route_version_id)
            ->whereIn('checkpoint_id', [$previous->checkpoint_id, $checkpoint->id])
            ->get()
            ->keyBy('checkpoint_id');
        $from = $points->get((string) $previous->checkpoint_id);
        $to = $points->get($checkpoint->id);
        if ($from === null || $to === null || $to->position !== $from->position + 1) {
            return false;
        }

        return ServerFlags::tooFast($previous->scanned_at, $scannedAt, $to->walk_time_from_prev_s);
    }

    private function incident(Device $device, ChainEntry $entry): bool
    {
        $payload = $entry->payload;
        if (Incident::query()->whereKey($payload['incident_id'])->exists()) {
            return true;
        }
        if ($this->shift($device, $payload['shift_id']) === null
            || ($payload['nearest_checkpoint_id'] !== null && !$this->checkpoint($device, $payload['nearest_checkpoint_id'])->exists())
            || ($payload['scan_id'] !== null && !$this->deviceScan($device, $payload['scan_id'])->exists())) {
            return false;
        }

        Incident::query()->create([
            'id' => $payload['incident_id'],
            'shift_id' => $payload['shift_id'],
            'category' => $payload['category'],
            'note' => $payload['note'],
            'occurred_at' => TrustedTime::of($entry),
            'lat_e7' => $payload['lat_e7'],
            'lng_e7' => $payload['lng_e7'],
            'accuracy_dm' => $payload['accuracy_dm'],
            'nearest_checkpoint_id' => $payload['nearest_checkpoint_id'],
            'scan_id' => $payload['scan_id'],
            'chain_entry_id' => $entry->id,
        ]);

        return true;
    }

    /** A shift of this device: no entry can touch the shift of another phone. */
    private function shift(Device $device, string $id): ?Shift
    {
        return Shift::query()->whereKey($id)->where('device_id', $device->id)->first();
    }

    private function round(Device $device, string $id): ?Round
    {
        return Round::query()->whereKey($id)->whereHas('shift', fn (Builder $shift) => $shift->where('device_id', $device->id))->first();
    }

    /** A version of a route of the device's site; the route may have been deleted since. */
    private function routeVersion(Device $device, string $id): ?RouteVersion
    {
        return RouteVersion::query()
            ->whereKey($id)
            ->whereHas('route', fn (Builder $route) => $route->withTrashed()->where('site_id', $device->site_id))
            ->first();
    }

    /**
     * A checkpoint of the device's site, also one deleted since.
     *
     * @return Builder<Checkpoint>
     */
    private function checkpoint(Device $device, string $id): Builder
    {
        return Checkpoint::withTrashed()->whereKey($id)->where('site_id', $device->site_id);
    }

    /**
     * A scan in this device's chain.
     *
     * @return Builder<Scan>
     */
    private function deviceScan(Device $device, string $id): Builder
    {
        return Scan::query()->whereKey($id)->whereHas('chainEntry', fn (Builder $entry) => $entry->where('device_id', $device->id));
    }
}
