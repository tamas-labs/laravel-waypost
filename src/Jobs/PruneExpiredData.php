<?php

declare(strict_types=1);

namespace Waypost\Jobs;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Waypost\Models\ChainEntry;
use Waypost\Models\Checkpoint;
use Waypost\Models\Device;
use Waypost\Models\DeviceDiagnostic;
use Waypost\Models\Incident;
use Waypost\Models\Media;
use Waypost\Models\NoDataAlert;
use Waypost\Models\Report;
use Waypost\Models\Round;
use Waypost\Models\Scan;
use Waypost\Models\Shift;
use Waypost\Models\Site;
use Waypost\Reports\Retention;
use Waypost\Support\Table;
use Waypost\Support\Time;

/**
 * The data retention (spec: Adatmegőrzés), daily: deletes a site's raw data older than its retention time
 * (Retention::days()): chain entries, projections (shifts with their rounds, scans and incidents; scans outside a
 * round), photos of scans and incidents with their files, the stored report PDFs (the reports row stays, without a
 * download), the phones' diagnostics and the "Nincs adat" alerts.
 *
 * The configuration stays (checkpoints with their photos, route versions, schedules, users), and so do the devices'
 * last_seq and last_hash: the chain goes on from them, and an entry deleted here is recognised by its HMAC if the
 * phone sends it again (ChainProcessor). A shift is deleted with everything in it once it ended before the cutoff;
 * a chain entry only when no remaining projection refers to it.
 */
final class PruneExpiredData implements ShouldQueue
{
    use Queueable;

    /** Rows deleted in one statement. */
    public const int CHUNK = 500;

    public function __construct()
    {
        $this->onConnection(config('waypost.queue.connection'));
        $this->onQueue(config('waypost.queue.queue'));
    }

    public function handle(): void
    {
        $now = Time::now();
        $disk = Storage::disk(config('waypost.storage.disk'));

        foreach (Site::withTrashed()->orderBy('id')->get() as $site) {
            $cutoff = $now->subDays(Retention::days($site));
            $deviceIds = self::ids(Device::withTrashed()->where('site_id', $site->id));
            $this->prune($disk, $deviceIds, $cutoff);
            $this->pruneSite($disk, $site, $cutoff);
        }
        // A phone with no site any more: the shortest retention.
        $this->prune($disk, self::ids(Device::withTrashed()->whereNull('site_id')), $now->subDays(Retention::MIN_DAYS));
        $this->pruneOrphanCheckpointPhotos($disk, $now->subDays(Retention::MIN_DAYS));
    }

    /**
     * Photos of a checkpoint that never came: the upload goes before the checkpoint request (offline training), and that
     * request may never arrive. Kept for the shortest retention, as there is no site to take it from; the photo of a
     * deleted checkpoint stays, with the checkpoint.
     */
    private function pruneOrphanCheckpointPhotos(Filesystem $disk, CarbonImmutable $cutoff): void
    {
        $checkpoints = Table::name('checkpoints');
        $this->deleteMedia($disk, Media::query()
            ->where('owner_type', Checkpoint::class)
            ->where('uploaded_at', '<', $cutoff->utc()->format(Time::DATABASE_FORMAT))
            ->whereNotExists(fn (QueryBuilder $checkpoint) => $checkpoint->from($checkpoints)
                ->whereColumn("{$checkpoints}.id", Table::name('media') . '.owner_id')));
    }

    /** @param  list<string>  $deviceIds */
    private function prune(Filesystem $disk, array $deviceIds, CarbonImmutable $cutoff): void
    {
        if ($deviceIds === []) {
            return;
        }
        $before = $cutoff->utc()->format(Time::DATABASE_FORMAT);

        // Shifts that ended (or, never closed, started) before the cutoff, with everything in them.
        $shifts = Shift::query()
            ->whereIn('device_id', $deviceIds)
            ->where(fn (Builder $old) => $old->where('ended_at', '<', $before)
                ->orWhere(fn (Builder $open) => $open->whereNull('ended_at')->where('started_at', '<', $before)));
        foreach (array_chunk(self::ids($shifts), self::CHUNK) as $shiftIds) {
            $incidentIds = self::ids(Incident::query()->whereIn('shift_id', $shiftIds));
            $roundIds = self::ids(Round::query()->whereIn('shift_id', $shiftIds));
            $scanIds = self::ids(Scan::query()->whereIn('round_id', $roundIds));
            $this->deleteMedia($disk, Media::query()->where(fn (Builder $media) => $media
                ->where(fn (Builder $ofIncidents) => $ofIncidents->where('owner_type', Incident::class)->whereIn('owner_id', $incidentIds))
                ->orWhere(fn (Builder $ofScans) => $ofScans->where('owner_type', Scan::class)->whereIn('owner_id', $scanIds))));
            self::deleteIn(Incident::query(), $incidentIds);
            // A scan of another shift's incident (a manual scan cited by it) stays with that incident.
            self::deleteIn(Scan::query()->whereNotExists(self::citingIncident()), $scanIds);
            self::deleteIn(Round::query()->whereDoesntHave('scans'), $roundIds);
            self::deleteIn(Shift::query()->whereDoesntHave('rounds')->whereDoesntHave('incidents'), $shiftIds);
        }

        // Scans outside a round before the cutoff.
        $entries = Table::name('chain_entries');
        $loose = Scan::query()
            ->whereNull('round_id')
            ->where('scanned_at', '<', $before)
            ->whereExists(fn (QueryBuilder $entry) => $entry->from($entries)
                ->whereColumn("{$entries}.id", Table::name('scans') . '.chain_entry_id')
                ->whereIn("{$entries}.device_id", $deviceIds))
            ->whereNotExists(self::citingIncident());
        foreach (array_chunk(self::ids($loose), self::CHUNK) as $scanIds) {
            $this->deleteMedia($disk, Media::query()->where('owner_type', Scan::class)->whereIn('owner_id', $scanIds));
            self::deleteIn(Scan::query(), $scanIds);
        }

        // Photos of scans and incidents taken before the cutoff (their links have expired), also never uploaded ones.
        $this->deleteMedia($disk, Media::query()
            ->whereIn('device_id', $deviceIds)
            ->whereIn('owner_type', [Scan::class, Incident::class])
            ->where('taken_at', '<', $before));

        // Chain entries no remaining projection refers to.
        $referenced = fn (string $table): Closure => fn (QueryBuilder $projection) => $projection->from(Table::name($table))
            ->whereColumn(Table::name($table) . '.chain_entry_id', "{$entries}.id");
        $old = ChainEntry::query()
            ->whereIn('device_id', $deviceIds)
            ->where('received_at', '<', $before)
            ->whereNotExists($referenced('shifts'))
            ->whereNotExists($referenced('scans'))
            ->whereNotExists($referenced('incidents'));
        foreach (array_chunk(self::ids($old), self::CHUNK) as $entryIds) {
            self::deleteIn(ChainEntry::query(), $entryIds);
        }

        DeviceDiagnostic::query()->whereIn('device_id', $deviceIds)->where('received_at', '<', $before)->delete();
    }

    /** The site's stored report PDFs and its alerts. */
    private function pruneSite(Filesystem $disk, Site $site, CarbonImmutable $cutoff): void
    {
        $before = $cutoff->utc()->format(Time::DATABASE_FORMAT);
        Report::query()
            ->where('site_id', $site->id)
            ->whereNotNull('path')
            ->where('period_end', '<', $before)
            ->get()
            ->each(function (Report $report) use ($disk): void {
                $disk->delete((string) $report->path);
                $report->fill(['path' => null])->save();
            });
        NoDataAlert::query()->where('site_id', $site->id)->where('deadline', '<', $before)->delete();
    }

    /** @param  Builder<Media>  $media */
    private function deleteMedia(Filesystem $disk, Builder $media): void
    {
        foreach ($media->get() as $photo) {
            if ($photo->path !== null) {
                $disk->delete($photo->path);
            }
            $photo->delete();
        }
    }

    /** An incident that cites the scan of the outer query. */
    private static function citingIncident(): Closure
    {
        $incidents = Table::name('incidents');

        return fn (QueryBuilder $incident) => $incident->from($incidents)->whereColumn("{$incidents}.scan_id", Table::name('scans') . '.id');
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return list<string>
     */
    private static function ids(Builder $query): array
    {
        return array_values(array_map(strval(...), $query->pluck($query->getModel()->getQualifiedKeyName())->all()));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $ids
     */
    private static function deleteIn(Builder $query, array $ids): void
    {
        if ($ids !== []) {
            $query->whereKey($ids)->delete();
        }
    }
}
