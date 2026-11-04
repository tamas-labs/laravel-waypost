<?php

declare(strict_types=1);

namespace Waypost\Reports;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Waypost\Enums\VerifyStatus;
use Waypost\Models\ChainEntry;
use Waypost\Models\Device;
use Waypost\Models\DeviceDiagnostic;
use Waypost\Models\Incident;
use Waypost\Models\Media;
use Waypost\Models\Round;
use Waypost\Models\Route;
use Waypost\Models\RouteVersion;
use Waypost\Models\Scan;
use Waypost\Models\Schedule;
use Waypost\Models\Shift;
use Waypost\Models\Site;
use Waypost\Schedule\Deadline;
use Waypost\Schedule\Expansion;
use Waypost\Support\Time;

/**
 * The data of a report (spec: Jelentések, figyelmeztetések és adatmegőrzés), from the projections and the schedules.
 *
 * - **Days.** A day is a local date of the site; its rounds are those of the schedule windows that start on it (a
 *   Monday 20:00–08:00 window is Monday's). A shift belongs to the day of its first round (without a round: its local
 *   start date), its incidents and its scans outside a round with it, so a night stays in one report also when a
 *   guard takes it over after midnight.
 * - **Rounds.** The expected times of the schedules as they are now (active schedule of an active route, while the
 *   schedule and the route existed and the route had a trained version), and the rounds the phones opened, matched
 *   by schedule and scheduled_at. An expected time without a round is missed once its deadline has passed. A round
 *   whose time the schedule no longer has (it changed since) is still in the report, on its start day by the
 *   schedule, or on the local date of its time.
 * - **Incomplete data.** A phone that sent data in the period has not synced since its data ended; chain entries
 *   wait (a gap, or a reference not on the server yet); a round is still open after its deadline; a photo has not
 *   been uploaded. A period with no phone at all is complete: its expected rounds are missed.
 */
final class Summarizer
{
    /** Search margin for shifts and scans before the period: a shift of the day before may reach into it. */
    private const int MARGIN_DAYS = 2;

    public function period(Site $site, string $fromDate, string $toDate, ?CarbonImmutable $now = null): Summary
    {
        $now ??= Time::now();
        $zone = new DateTimeZone($site->timezone);
        $periodStart = self::midnight($fromDate, $zone);
        $periodEnd = self::midnight(self::addDays($toDate, 1), $zone);
        // A window is shorter than a day: the rounds of the last day are over by the midnight after the next one.
        $searchEnd = self::midnight(self::addDays($toDate, 2), $zone);
        $searchStart = self::midnight(self::addDays($fromDate, -self::MARGIN_DAYS), $zone);
        $inPeriod = fn (string $day): bool => $day >= $fromDate && $day <= $toDate;

        /** @var Collection<string, Route> $routes */
        $routes = Route::query()->withTrashed()->where('site_id', $site->id)
            ->with(['versions' => fn ($versions) => $versions->with('points')->orderBy('created_at')->orderBy('version')])
            ->get()->keyBy('id');
        /** @var Collection<string, Schedule> $schedules */
        $schedules = Schedule::query()->withTrashed()->whereIn('route_id', $routes->keys()->all())->get()->keyBy('id');

        // The start day of every time of the schedules around the period, by schedule and instant.
        $startDays = [];
        $expected = [];
        foreach ($schedules as $schedule) {
            foreach (Expansion::expand($schedule, $site->timezone, self::addDays($fromDate, -self::MARGIN_DAYS), self::addDays($toDate, 1)) as $time) {
                $startDays[self::key($schedule->id, $time->utc)] = $time->startDay;
                $route = $routes->get($schedule->route_id);
                if ($route !== null && $inPeriod($time->startDay) && self::expects($route, $schedule, $time->utc)) {
                    $expected[self::key($schedule->id, $time->utc)] = [$schedule, $route, $time->startDay, $time->utc];
                }
            }
        }
        $startDayOf = fn (Round $round): string => $startDays[self::key($round->schedule_id, $round->scheduled_at)]
            ?? self::localDate($round->scheduled_at, $zone);

        $shifts = $this->shifts($site, $periodStart, $searchStart, $searchEnd);
        $shiftDays = [];
        foreach ($shifts as $shift) {
            $days = $shift->rounds->map($startDayOf)->sort()->values();
            $shiftDays[$shift->id] = $days->first() ?? self::localDate($shift->started_at, $zone);
        }

        // The rows: the rounds of the period's days, then the expected times without one.
        $rows = [];
        foreach ($shifts->flatMap(fn (Shift $shift) => $shift->rounds) as $round) {
            /** @var Round $round */
            $day = $startDayOf($round);
            if (!$inPeriod($day)) {
                continue;
            }
            $key = self::key($round->schedule_id, $round->scheduled_at);
            $version = $round->routeVersion ?? throw new LogicException('A round has its route version.');
            $rows[] = ['day' => $day, 'route' => $version->route_id, 'schedule' => $round->schedule_id, 'at' => CarbonImmutable::instance($round->scheduled_at),
                'limit' => $round->time_limit_min, 'expected' => isset($expected[$key]), 'round' => $round, 'version' => $version];
            unset($expected[$key]);
        }
        foreach ($expected as [$schedule, $route, $day, $at]) {
            $rows[] = ['day' => $day, 'route' => $route->id, 'schedule' => $schedule->id, 'at' => $at,
                'limit' => $schedule->time_limit_min, 'expected' => true, 'round' => null, 'version' => self::versionAt($route, $at)];
        }

        $dayTimes = [];
        foreach ($rows as $row) {
            $dayTimes["{$row['route']}|{$row['day']}"][] = $row['at'];
        }
        $rounds = array_map(function (array $row) use ($dayTimes, $now): RoundRow {
            $deadline = Deadline::of($row['at'], $row['limit'], $dayTimes["{$row['route']}|{$row['day']}"]);
            $round = $row['round'];
            $scans = $round === null ? [] : array_values($round->scans->sortBy(fn (Scan $scan) => Time::milliseconds($scan->scanned_at))->all());
            $scanned = array_map(fn (Scan $scan): ?string => $scan->checkpoint_id, $scans);
            $points = $row['version'] === null ? [] : $row['version']->points->sortBy('position')->pluck('checkpoint_id')->all();

            return new RoundRow(
                startDay: $row['day'],
                routeId: $row['route'],
                scheduleId: $row['schedule'],
                scheduledAt: $row['at'],
                timeLimitMin: $row['limit'],
                deadline: $deadline,
                expected: $row['expected'],
                round: $round,
                version: $row['version'],
                outcome: match (true) {
                    $round !== null => RoundOutcome::of($round->status),
                    $deadline->lessThanOrEqualTo($now) => RoundOutcome::Missed,
                    default => RoundOutcome::Upcoming,
                },
                scans: $scans,
                missingCheckpointIds: array_values(array_diff(array_map(strval(...), $points), $scanned)),
            );
        }, $rows);
        usort($rounds, fn (RoundRow $a, RoundRow $b): int => [Time::milliseconds($a->scheduledAt), $a->routeId, $a->scheduleId]
            <=> [Time::milliseconds($b->scheduledAt), $b->routeId, $b->scheduleId]);

        $periodShifts = $shifts->filter(fn (Shift $shift): bool => $inPeriod($shiftDays[$shift->id]))->values();
        $incidents = Incident::query()->whereIn('shift_id', $periodShifts->modelKeys())->orderBy('occurred_at')->orderBy('id')->get();
        $looseScans = $this->scansOutsideRounds($site, $shifts, $shiftDays, $zone, $searchStart, $searchEnd)
            ->filter(fn (array $scan): bool => $inPeriod($scan[1]))->map(fn (array $scan): Scan => $scan[0]);
        $scans = collect($rounds)->flatMap(fn (RoundRow $row): array => $row->scans)->concat($looseScans)
            ->sortBy(fn (Scan $scan) => Time::milliseconds($scan->scanned_at))->values();

        $dataEnd = collect($rounds)->reduce(fn (CarbonImmutable $end, RoundRow $row): CarbonImmutable => $row->deadline->max($end), $periodEnd);
        $devices = $this->devices($site, $periodStart, $dataEnd);
        $deviceIds = array_values(array_map(strval(...), $devices->modelKeys()));

        return new Summary(
            site: $site,
            fromDate: $fromDate,
            toDate: $toDate,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            dataEnd: $dataEnd,
            generatedAt: $now,
            rounds: $rounds,
            shifts: array_values($periodShifts->sortBy(fn (Shift $shift) => Time::milliseconds($shift->started_at))->all()),
            incidents: array_values($incidents->all()),
            flaggedScans: array_values($scans->filter(fn (Scan $scan): bool => $scan->flags !== [])->all()),
            badEntries: $this->badEntries($deviceIds, $periodStart, $periodEnd),
            diagnostics: DeviceDiagnostic::query()->whereIn('device_id', $deviceIds)
                ->where('occurred_at', '>=', self::text($periodStart))->where('occurred_at', '<', self::text($periodEnd))->count(),
            deviceIds: $deviceIds,
            incompleteReasons: $this->incompleteReasons($devices, $rounds, $scans, $incidents, $now, $dataEnd, $searchStart, $searchEnd),
        );
    }

    /**
     * The site's shifts that may belong to the period: started around it, or with a round in it.
     *
     * @return Collection<int, Shift>
     */
    private function shifts(Site $site, CarbonImmutable $periodStart, CarbonImmutable $searchStart, CarbonImmutable $searchEnd): Collection
    {
        $withRounds = Round::query()
            ->whereHas('shift', fn (Builder $shift) => $shift->where('site_id', $site->id))
            ->where('scheduled_at', '>=', self::text($periodStart))
            ->where('scheduled_at', '<', self::text($searchEnd))
            ->distinct()
            ->pluck('shift_id')
            ->all();

        return Shift::query()
            ->where('site_id', $site->id)
            ->where(fn (Builder $shifts) => $shifts
                ->whereIn('id', $withRounds)
                ->orWhere(fn (Builder $started) => $started
                    ->where('started_at', '>=', self::text($searchStart))
                    ->where('started_at', '<', self::text($searchEnd))))
            ->with([
                'guardUser' => fn ($guard) => $guard->withTrashed(),
                'rounds.scans',
                'rounds.routeVersion.points',
            ])
            ->orderBy('started_at')
            ->get();
    }

    /**
     * The scans outside a round of the site's phones around the period, each with its day: that of the phone's
     * shift it happened in, or its local date.
     *
     * @param  Collection<int, Shift>  $shifts
     * @param  array<string, string>  $shiftDays
     * @return BaseCollection<int, array{Scan, string}>
     */
    private function scansOutsideRounds(Site $site, Collection $shifts, array $shiftDays, DateTimeZone $zone, CarbonImmutable $searchStart, CarbonImmutable $searchEnd): BaseCollection
    {
        $deviceIds = Device::query()->withTrashed()->where('site_id', $site->id)->pluck('id')->all();

        return Scan::query()
            ->whereNull('round_id')
            ->whereHas('chainEntry', fn (Builder $entry) => $entry->whereIn('device_id', $deviceIds))
            ->where('scanned_at', '>=', self::text($searchStart))
            ->where('scanned_at', '<', self::text($searchEnd))
            ->with('chainEntry:id,device_id')
            ->get()
            ->map(function (Scan $scan) use ($shifts, $shiftDays, $zone): array {
                $at = Time::milliseconds($scan->scanned_at);
                $deviceId = $scan->chainEntry?->device_id;
                $shift = $shifts->last(fn (Shift $shift): bool => $shift->device_id === $deviceId
                    && Time::milliseconds($shift->started_at) <= $at
                    && ($shift->ended_at === null || $at <= Time::milliseconds($shift->ended_at)));

                return [$scan, $shift === null ? self::localDate($scan->scanned_at, $zone) : $shiftDays[$shift->id]];
            })
            ->toBase();
    }

    /**
     * The site's phones the period's data may come from: enrolled before its data ended, not revoked before it
     * started; a deleted phone sends nothing more.
     *
     * @return Collection<int, Device>
     */
    private function devices(Site $site, CarbonImmutable $periodStart, CarbonImmutable $dataEnd): Collection
    {
        return Device::query()
            ->where('site_id', $site->id)
            ->where('enrolled_at', '<', self::text($dataEnd))
            ->where(fn (Builder $devices) => $devices->whereNull('revoked_at')->orWhere('revoked_at', '>', self::text($periodStart)))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<string>  $deviceIds
     * @return array<string, int>
     */
    private function badEntries(array $deviceIds, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $counts = ChainEntry::query()
            ->whereIn('device_id', $deviceIds)
            ->where('verify_status', '!=', VerifyStatus::Ok->value)
            ->where('device_time', '>=', self::text($periodStart))
            ->where('device_time', '<', self::text($periodEnd))
            ->toBase()
            ->selectRaw('verify_status, count(*) as entries')
            ->groupBy('verify_status')
            ->pluck('entries', 'verify_status')
            ->all();
        ksort($counts);

        return array_map(intval(...), $counts);
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @param  list<RoundRow>  $rounds
     * @param  BaseCollection<int, Scan>  $scans
     * @param  Collection<int, Incident>  $incidents
     * @return list<IncompleteReason>
     */
    private function incompleteReasons(
        Collection $devices,
        array $rounds,
        BaseCollection $scans,
        Collection $incidents,
        CarbonImmutable $now,
        CarbonImmutable $dataEnd,
        CarbonImmutable $searchStart,
        CarbonImmutable $searchEnd,
    ): array {
        $reasons = [];

        // A revoked phone that closed its last upload sends nothing more.
        $behind = $devices->contains(fn (Device $device): bool => !($device->revoked_at !== null && $device->upload_closed_at !== null)
            && ($device->last_seen_at === null || $device->last_seen_at->lessThan($dataEnd)));
        if ($behind) {
            $reasons[] = IncompleteReason::NotSynced;
        }

        $waiting = ChainEntry::query()
            ->whereIn('device_id', $devices->modelKeys())
            ->where('device_time', '>=', self::text($searchStart))
            ->where('device_time', '<', self::text($searchEnd))
            ->where(fn (Builder $entries) => $entries
                ->where('verify_status', VerifyStatus::Gap->value)
                ->orWhere(fn (Builder $ok) => $ok->where('verify_status', VerifyStatus::Ok->value)->whereNull('projected_at')))
            ->exists();
        if ($waiting) {
            $reasons[] = IncompleteReason::PendingEntries;
        }

        foreach ($rounds as $row) {
            if ($row->outcome === RoundOutcome::Pending && $row->deadline->lessThanOrEqualTo($now)) {
                $reasons[] = IncompleteReason::PendingRounds;
                break;
            }
        }

        $photosMissing = Media::query()
            ->whereNull('uploaded_at')
            ->where(fn (Builder $media) => $media
                ->where(fn (Builder $ofScans) => $ofScans->where('owner_type', Scan::class)->whereIn('owner_id', $scans->map(fn (Scan $scan): string => $scan->id)->all()))
                ->orWhere(fn (Builder $ofIncidents) => $ofIncidents->where('owner_type', Incident::class)->whereIn('owner_id', $incidents->modelKeys())))
            ->exists();
        if ($photosMissing) {
            $reasons[] = IncompleteReason::MissingPhotos;
        }

        return $reasons;
    }

    /** Whether the schedule expects a round at the time: both it and its route are active, existed then, and the route had a trained version. */
    private static function expects(Route $route, Schedule $schedule, CarbonImmutable $at): bool
    {
        foreach ([$route, $schedule] as $record) {
            if (!$record->active || ($record->created_at !== null && $record->created_at->greaterThan($at))
                || ($record->deleted_at !== null && $record->deleted_at->lessThanOrEqualTo($at))) {
                return false;
            }
        }

        return self::versionAt($route, $at) !== null;
    }

    /** The route's latest version at the time. */
    private static function versionAt(Route $route, CarbonImmutable $at): ?RouteVersion
    {
        return $route->versions->last(fn (RouteVersion $version): bool => $version->created_at === null || $version->created_at->lessThanOrEqualTo($at));
    }

    private static function key(string $scheduleId, DateTimeInterface $at): string
    {
        return $scheduleId . '|' . Time::milliseconds($at);
    }

    /** The UTC instant of a local date's midnight (by the schedule rules, should midnight not exist). */
    private static function midnight(string $date, DateTimeZone $zone): CarbonImmutable
    {
        $naive = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC') ?: throw new InvalidArgumentException("Not a date: {$date}");

        return CarbonImmutable::createFromTimestampUTC(Expansion::resolve($naive->getTimestamp(), $zone));
    }

    private static function addDays(string $date, int $days): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')?->addDays($days)->format('Y-m-d')
            ?? throw new InvalidArgumentException("Not a date: {$date}");
    }

    private static function localDate(DateTimeInterface $time, DateTimeZone $zone): string
    {
        return CarbonImmutable::instance($time)->setTimezone($zone)->format('Y-m-d');
    }

    /** A query condition's time: as text, the query builder would cut the milliseconds. */
    private static function text(DateTimeInterface $time): string
    {
        return CarbonImmutable::instance($time)->utc()->format(Time::DATABASE_FORMAT);
    }
}
