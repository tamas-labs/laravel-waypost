<?php

declare(strict_types=1);

namespace Waypost\Reports;

use Carbon\CarbonImmutable;
use Closure;
use LogicException;
use Waypost\Models\Round;
use Waypost\Models\RouteVersion;
use Waypost\Models\Scan;
use Waypost\Schedule\Deadline;
use Waypost\Support\Time;

/**
 * The rows of a report's rounds (Summarizer): the rounds the phones opened on the period's days, then the expected
 * times no round took; each with its deadline (by the other times of its route that day), outcome, scans in time
 * order and the checkpoints it missed.
 */
final class RoundRows
{
    private function __construct()
    {
    }

    /**
     * @param  iterable<Round>  $opened  the rounds the phones opened around the period
     * @param  Closure(string): bool  $inPeriod  whether a local date is a day of the period
     * @return list<RoundRow> rounds first, then the untaken times, unsorted
     */
    public static function of(iterable $opened, ExpectedRounds $expected, Closure $inPeriod, CarbonImmutable $now): array
    {
        $rows = [];
        foreach ($opened as $round) {
            $day = $expected->startDayOf($round);
            if (!$inPeriod($day)) {
                continue;
            }
            $version = $round->routeVersion ?? throw new LogicException('A round has its route version.');
            $rows[] = ['day' => $day, 'route' => $version->route_id, 'schedule' => $round->schedule_id, 'at' => CarbonImmutable::instance($round->scheduled_at),
                'limit' => $round->time_limit_min, 'expected' => $expected->take($round), 'round' => $round, 'version' => $version];
        }
        foreach ($expected->untaken() as [$schedule, $route, $day, $at, $version]) {
            $rows[] = ['day' => $day, 'route' => $route->id, 'schedule' => $schedule->id, 'at' => $at,
                'limit' => $schedule->time_limit_min, 'expected' => true, 'round' => null, 'version' => $version];
        }

        $dayTimes = [];
        foreach ($rows as $row) {
            $dayTimes["{$row['route']}|{$row['day']}"][] = $row['at'];
        }

        return array_map(fn (array $row): RoundRow => self::row($row, $dayTimes["{$row['route']}|{$row['day']}"], $now), $rows);
    }

    /**
     * @param  array{day: string, route: string, schedule: string, at: CarbonImmutable, limit: int|null, expected: bool, round: Round|null, version: RouteVersion|null}  $row
     * @param  list<CarbonImmutable>  $dayTimes  the times of the row's route on its day
     */
    private static function row(array $row, array $dayTimes, CarbonImmutable $now): RoundRow
    {
        $deadline = Deadline::of($row['at'], $row['limit'], $dayTimes);
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
    }
}
