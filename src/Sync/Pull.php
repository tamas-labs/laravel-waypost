<?php

declare(strict_types=1);

namespace Waypost\Sync;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Waypost\Auth\Caller;
use Waypost\Http\Entities;
use Waypost\Models\Checkpoint;
use Waypost\Models\Device;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Route;
use Waypost\Models\RouteVersion;
use Waypost\Models\Schedule;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Table;
use Waypost\Support\Time;

/**
 * One page of GET /sync/pull (spec: API-szerződés / Pull, api/sync-pull.response): the configuration records of
 * the caller's sites changed since the cursor, in (change time, id) order over every table. A record goes into
 * "changes" if the caller sees it, otherwise into "deleted" (soft deleted, a revoked device, a user detached from
 * the caller's sites: Q3). A route version is never split from its checkpoints.
 *
 * If the caller's sites changed since the cursor (a supervisor was assigned to a site or detached from one, a site
 * was created or deleted), the page starts a full state again (reset): an assigned site's old records lie before
 * the cursor, and a detached site's records do not change at all.
 */
final class Pull
{
    /** Records per page (a route version with all of its checkpoints may exceed it alone). */
    public const int PAGE_SIZE = 500;

    /**
     * Records changed in the last milliseconds wait for the next pull: a transaction that wrote them may not have
     * committed yet, and once the cursor passed their change time they would never come.
     */
    public const int SETTLE_MS = 5000;

    /** The lists of "changes" in the order of the schema; the key column orders a table for the cursor. */
    private const array KEYS = [
        'sites' => 'updated_at',
        'users' => 'updated_at',
        'devices' => 'updated_at',
        'checkpoints' => 'updated_at',
        'routes' => 'updated_at',
        'route_versions' => 'created_at',
        'schedules' => 'updated_at',
        'report_subscriptions' => 'updated_at',
    ];

    /** What the records of a list are loaded with once they are on the page. */
    private const array RELATIONS = ['users' => ['sites'], 'route_versions' => ['points']];

    /** The lists of "deleted": route versions are never deleted on their own, and sites go with a reset. */
    private const array DELETED = ['sites', 'users', 'devices', 'checkpoints', 'routes', 'schedules', 'report_subscriptions'];

    /**
     * @return array{cursor: string, has_more: bool, reset: bool, changes: array<string, list<array<string, mixed>>>, deleted: array<string, list<string>>}
     *
     * @throws ValidationException a cursor this server did not give out
     */
    public function page(Caller $caller, ?string $cursor): array
    {
        $siteIds = $caller->siteIds();
        $scope = PullCursor::scope($siteIds);
        $from = $cursor === null ? null : (PullCursor::decode($cursor)
            ?? throw ValidationException::withMessages(['cursor' => ['Hibás kurzor; kurzor nélkül a teljes állapot kérhető le.']]));
        $reset = $from === null || $from->scope !== $scope;
        if ($reset) {
            $from = PullCursor::start($scope);
        }
        $until = Time::now()->subMilliseconds(self::SETTLE_MS);

        // First only the keys and the sizes of the candidates (each table's next PAGE_SIZE + 1), then the whole records
        // of those that make the page: the others are not loaded, nor their checkpoints and sites.
        $queries = $this->queries($caller, $siteIds);
        $candidates = [];
        foreach ($queries as $list => $query) {
            $key = self::KEYS[$list];
            $keys = (clone $query)
                ->select($query->qualifyColumns(['id', $key]))
                ->when($list === 'route_versions', fn (Builder $versions) => $versions->withCount('points'))
                ->whereRowValues($query->qualifyColumns([$key, 'id']), '>', [$from->time->format(Time::DATABASE_FORMAT), $from->id])
                ->where($query->qualifyColumn($key), '<=', $until->format(Time::DATABASE_FORMAT))
                ->orderBy($query->qualifyColumn($key))
                ->orderBy($query->qualifyColumn('id'))
                ->limit(self::PAGE_SIZE + 1)
                ->get();
            foreach ($keys as $record) {
                $changed = $record->getAttribute($key);
                if (!$changed instanceof DateTimeInterface) {
                    throw new LogicException("A synced record has its {$key}.");
                }
                $time = CarbonImmutable::instance($changed)->utc();
                $candidates[] = [
                    'order' => $time->format(Time::DATABASE_FORMAT) . ' ' . $record->getKey(),
                    'time' => $time,
                    'list' => $list,
                    'id' => (string) $record->getKey(),
                    'size' => 1 + (int) $record->getAttribute('points_count'),
                ];
            }
        }
        usort($candidates, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $page = [];
        $records = 0;
        foreach ($candidates as $candidate) {
            if ($records > 0 && $records + $candidate['size'] > self::PAGE_SIZE) {
                break;
            }
            $records += $candidate['size'];
            $page[] = $candidate;
        }
        $loaded = [];
        foreach (array_unique(array_column($page, 'list')) as $list) {
            $loaded[$list] = (clone $queries[$list])
                ->with(self::RELATIONS[$list] ?? [])
                ->whereKey(array_column(array_filter($page, fn (array $candidate): bool => $candidate['list'] === $list), 'id'))
                ->get()
                ->keyBy(fn (Model $record): string => (string) $record->getKey());
        }

        $lists = array_keys(self::KEYS);
        array_splice($lists, array_search('route_versions', $lists, true) + 1, 0, ['route_version_checkpoints']);
        $changes = array_fill_keys($lists, []);
        $deleted = array_fill_keys(self::DELETED, []);
        $last = $from;
        foreach ($page as $candidate) {
            $last = new PullCursor($candidate['time'], $candidate['id'], $scope);
            $list = $candidate['list'];
            $record = $loaded[$list][$candidate['id']] ?? null;
            if ($record === null) {
                // Gone since the first query; config records are only soft deleted, so this is the odd one out.
                continue;
            }
            if ($this->sees($caller, $siteIds, $record)) {
                $changes[$list][] = $this->entity($caller, $record);
                if ($record instanceof RouteVersion) {
                    array_push($changes['route_version_checkpoints'], ...$record->points->map(Entities::routeVersionCheckpoint(...))->all());
                }
            } elseif (!$reset) {
                // A full state needs no deletions: the client starts it from an empty configuration.
                $deleted[$list][] = (string) $record->getKey();
            }
        }

        return [
            'cursor' => $last->encode(),
            'has_more' => count($page) < count($candidates),
            'reset' => $reset,
            'changes' => $changes,
            'deleted' => $deleted,
        ];
    }

    /**
     * The records each list may hold: those of the caller's sites, deleted ones included (without RELATIONS).
     *
     * @param  list<string>  $siteIds
     * @return array<string, Builder<covariant Model>>
     */
    private function queries(Caller $caller, array $siteIds): array
    {
        $self = $caller->device === null ? $caller->user?->id : null;

        return [
            'sites' => Site::query()->whereKey($siteIds),
            'users' => User::withTrashed()->where(function (Builder $users) use ($siteIds, $self): void {
                $users->whereHas('sites', fn (Builder $sites) => $sites->whereKey($siteIds))
                    ->orWhereIn('id', DB::table(Table::name('site_user_detachments'))->whereIn('site_id', $siteIds)->select('user_id'));
                if ($self !== null) {
                    $users->orWhereKey($self);
                }
            }),
            'devices' => Device::withTrashed()->whereIn('site_id', $siteIds),
            'checkpoints' => Checkpoint::withTrashed()->whereIn('site_id', $siteIds),
            'routes' => Route::withTrashed()->whereIn('site_id', $siteIds),
            'route_versions' => RouteVersion::query()
                ->whereIn('route_id', Route::query()->whereIn('site_id', $siteIds)->select('id')),
            'schedules' => Schedule::withTrashed()
                ->whereIn('route_id', Route::withTrashed()->whereIn('site_id', $siteIds)->select('id')),
            'report_subscriptions' => ReportSubscription::withTrashed()->whereIn('site_id', $siteIds),
        ];
    }

    /** @param  list<string>  $siteIds */
    private function sees(Caller $caller, array $siteIds, Model $record): bool
    {
        return match (true) {
            $record instanceof User => !$record->trashed()
                && (($caller->device === null && $record->id === $caller->user?->id) || array_intersect($record->sites->modelKeys(), $siteIds) !== []),
            $record instanceof Device => !$record->trashed() && $record->revoked_at === null,
            $record instanceof Site, $record instanceof Checkpoint, $record instanceof Route,
            $record instanceof Schedule, $record instanceof ReportSubscription => !$record->trashed(),
            default => true,
        };
    }

    /** @return array<string, mixed> */
    private function entity(Caller $caller, Model $record): array
    {
        return match (true) {
            $record instanceof Site => Entities::site($record),
            $record instanceof User => Entities::user($record, $caller),
            $record instanceof Device => Entities::device($record),
            $record instanceof Checkpoint => Entities::checkpoint($record),
            $record instanceof Route => Entities::route($record),
            $record instanceof RouteVersion => Entities::routeVersion($record),
            $record instanceof Schedule => Entities::schedule($record),
            $record instanceof ReportSubscription => Entities::reportSubscription($record),
            default => throw new LogicException('Not a configuration record: ' . $record::class),
        };
    }
}
