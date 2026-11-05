<?php

declare(strict_types=1);

namespace Waypost\Reports;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Waypost\Models\Round;
use Waypost\Models\Route;
use Waypost\Models\RouteVersion;
use Waypost\Models\Schedule;
use Waypost\Models\Site;
use Waypost\Schedule\Expansion;
use Waypost\Support\Time;

/**
 * The times the schedules of a site expect a round in a period (Summarizer), from the schedules as they are now: the
 * times of an active schedule of an active route, while the schedule and the route existed and the route had a
 * trained version, that start on a day of the period. Also the start day of every time around it, by schedule and
 * instant, for the rounds the phones opened.
 *
 * A round the phones opened takes its time (take()); what no round took is missed, or still to come (untaken()).
 */
final class ExpectedRounds
{
    /**
     * @param  array<string, string>  $startDays  by key()
     * @param  array<string, array{Schedule, Route, string, CarbonImmutable, RouteVersion|null}>  $expected  by key(): schedule, route, start day, time, version
     */
    private function __construct(private readonly DateTimeZone $zone, private readonly array $startDays, private array $expected)
    {
    }

    /**
     * @param  string  $fromDate  the first local date of the period
     * @param  string  $toDate  the last one
     * @param  string  $searchFromDate  the first local date of the times whose start day is needed
     */
    public static function of(Site $site, string $fromDate, string $toDate, string $searchFromDate): self
    {
        /** @var Collection<string, Route> $routes */
        $routes = Route::query()->withTrashed()->where('site_id', $site->id)
            ->with(['versions' => fn ($versions) => $versions->with('points')->orderBy('created_at')->orderBy('version')])
            ->get()->keyBy('id');
        /** @var Collection<string, Schedule> $schedules */
        $schedules = Schedule::query()->withTrashed()->whereIn('route_id', $routes->keys()->all())->get()->keyBy('id');

        $startDays = [];
        $expected = [];
        $dayAfter = CarbonImmutable::createFromFormat('!Y-m-d', $toDate, 'UTC')?->addDay()->format('Y-m-d') ?? $toDate;
        foreach ($schedules as $schedule) {
            foreach (Expansion::expand($schedule, $site->timezone, $searchFromDate, $dayAfter) as $time) {
                $key = self::key($schedule->id, $time->utc);
                $startDays[$key] = $time->startDay;
                $route = $routes->get($schedule->route_id);
                if ($route !== null && $time->startDay >= $fromDate && $time->startDay <= $toDate && self::expects($route, $schedule, $time->utc)) {
                    $expected[$key] = [$schedule, $route, $time->startDay, $time->utc, self::versionAt($route, $time->utc)];
                }
            }
        }

        return new self(new DateTimeZone($site->timezone), $startDays, $expected);
    }

    /** The round's start day by its schedule, or the local date of its time if the schedule no longer has it. */
    public function startDayOf(Round $round): string
    {
        return $this->startDays[self::key($round->schedule_id, $round->scheduled_at)]
            ?? CarbonImmutable::instance($round->scheduled_at)->setTimezone($this->zone)->format('Y-m-d');
    }

    /** Whether the round's time is an expected one; it is then taken, and no longer among the untaken(). */
    public function take(Round $round): bool
    {
        $key = self::key($round->schedule_id, $round->scheduled_at);
        $expected = isset($this->expected[$key]);
        unset($this->expected[$key]);

        return $expected;
    }

    /** @return list<array{Schedule, Route, string, CarbonImmutable, RouteVersion|null}> schedule, route, start day, time, version */
    public function untaken(): array
    {
        return array_values($this->expected);
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
}
