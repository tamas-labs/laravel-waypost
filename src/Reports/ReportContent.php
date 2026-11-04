<?php

declare(strict_types=1);

namespace Waypost\Reports;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Waypost\Enums\ChainFlag;
use Waypost\Enums\IncidentCategory;
use Waypost\Enums\ReportFrequency;
use Waypost\Enums\ShiftClosedBy;
use Waypost\Enums\VerifyStatus;
use Waypost\Http\SignedUrls;
use Waypost\Models\ChainEntry;
use Waypost\Models\Checkpoint;
use Waypost\Models\Incident;
use Waypost\Models\Media;
use Waypost\Models\Route;
use Waypost\Models\Scan;
use Waypost\Models\Shift;
use Waypost\Models\User;
use Waypost\Support\Time;

/**
 * What a report shows (spec: Jelentések / Tartalom), from a Summary: texts and times ready for the templates
 * (resources/views/mail, resources/views/pdf), in Hungarian and on the site's clock.
 */
final class ReportContent
{
    /** The style of a point's row (the design's email/shift-report.blade.php) by its most serious flag. */
    private const array FLAG_STYLES = [
        'mock_location' => 'warning',
        'manual' => 'manual',
        'unknown_tag' => 'warning',
        'too_fast' => 'suspicious',
        'time_suspect' => 'suspicious',
        'far_from_checkpoint' => 'warning',
        'out_of_order' => 'warning',
        'out_of_round' => 'warning',
    ];

    /** The flagged scans a weekly or monthly report lists. */
    public const int FLAGGED_SCANS_LISTED = 100;

    private LocalFormat $format;

    /** @var Collection<string, Checkpoint> */
    private Collection $checkpoints;

    /** @var Collection<string, Route> */
    private Collection $routes;

    /** @var Collection<int, Media> */
    private Collection $media;

    /**
     * @param  (Closure(Media): ?string)|null  $embed  the photo as a data URI for a PDF (Thumbnails::dataUri()),
     *                                                null: the photo's signed link (an e-mail)
     */
    private function __construct(private readonly Summary $summary, private readonly ?Closure $embed)
    {
        $site = $summary->site;
        $this->format = new LocalFormat($site->timezone);
        $this->checkpoints = Checkpoint::withTrashed()->where('site_id', $site->id)->get()->keyBy('id');
        $this->routes = Route::withTrashed()->where('site_id', $site->id)->get()->keyBy('id');
        $scanIds = [
            ...array_map(fn (Scan $scan): string => $scan->id, $summary->flaggedScans),
            ...array_filter(array_map(fn (Incident $incident): ?string => $incident->scan_id, $summary->incidents)),
        ];
        $this->media = Media::query()
            ->where(fn (Builder $media) => $media
                ->where(fn (Builder $ofScans) => $ofScans->where('owner_type', Scan::class)->whereIn('owner_id', $scanIds))
                ->orWhere(fn (Builder $ofIncidents) => $ofIncidents->where('owner_type', Incident::class)
                    ->whereIn('owner_id', array_map(fn (Incident $incident): string => $incident->id, $summary->incidents))))
            ->orderBy('taken_at')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> resources/views/mail/shift-report.blade.php */
    public static function shift(Summary $summary, Shift $shift): array
    {
        $content = new self($summary, null);
        $format = $content->format;
        $end = $shift->ended_at ?? $summary->periodEnd;

        return [
            'title' => Labels::report(ReportFrequency::Shift),
            'site' => ['name' => $summary->site->name],
            'shift' => [
                'date_label' => LocalFormat::dateRange($format->local($shift->started_at)->format('Y-m-d'), $format->local($end)->format('Y-m-d')),
                'from' => $format->time($shift->started_at),
                'to' => $format->time($end),
                'guard' => self::userName($shift->guardUser()->withTrashed()->first()),
                'closed_by' => $shift->closed_by?->value,
                'closed_by_text' => Labels::closedBy($shift->closed_by),
                'handover_guard' => $content->handoverGuard($shift),
                'late_sync_at' => $content->lateSyncAt($shift, $end),
            ],
            'incomplete' => self::incomplete($summary),
            'totals' => $content->totals(),
            'show_route' => $content->showsRoutes(),
            'rounds' => array_map($content->round(...), $summary->rounds),
            'incidents' => array_map($content->incident(...), $summary->incidents),
            'outside' => array_map($content->outsideScan(...), $summary->scansOutsideRounds),
            'chain' => self::chain($summary),
            'photoLinkDays' => Retention::days($summary->site),
        ];
    }

    /**
     * @param  (Closure(Media): ?string)|null  $embed  see the constructor
     * @return array<string, mixed> resources/views/mail/period-report.blade.php, resources/views/pdf/period-report.blade.php
     */
    public static function period(Summary $summary, ReportFrequency $type, ?Closure $embed = null): array
    {
        $content = new self($summary, $embed);
        $format = $content->format;
        // The daily report lists every flagged scan; the weekly and monthly ones count them by flag, and their PDF
        // lists the first ones only (a misplaced checkpoint flags every scan of a month).
        $flagged = $type === ReportFrequency::Daily
            ? $summary->flaggedScans
            : array_slice($summary->flaggedScans, 0, self::FLAGGED_SCANS_LISTED);
        $problems = array_values(array_filter($summary->rounds, fn (RoundRow $row): bool => in_array(
            $row->outcome,
            [RoundOutcome::Missed, RoundOutcome::Incomplete, RoundOutcome::Pending],
            true,
        )));

        return [
            'title' => Labels::report($type),
            'type' => $type->value,
            'site' => ['name' => $summary->site->name],
            'period_label' => match ($type) {
                ReportFrequency::Monthly => LocalFormat::month($summary->fromDate),
                default => $summary->fromDate === $summary->toDate
                    ? LocalFormat::dateWithWeekday($summary->fromDate)
                    : LocalFormat::dateRange($summary->fromDate, $summary->toDate),
            },
            'incomplete' => self::incomplete($summary),
            'totals' => $content->totals(),
            'days' => $summary->fromDate === $summary->toDate ? [] : $content->days(),
            'show_route' => $content->showsRoutes(),
            'problem_rounds' => array_map(fn (RoundRow $row): array => [
                // The date the round was due, like the scans' and the incidents': a night's 01:00 is the next day's.
                'day' => LocalFormat::shortDate($format->local($row->scheduledAt)->format('Y-m-d')),
                ...$content->round($row),
                'missing' => implode(', ', array_map($content->checkpointName(...), $row->missingCheckpointIds)),
            ], $problems),
            'shifts' => array_map(fn (Shift $shift): array => [
                'date' => LocalFormat::shortDate($format->local($shift->started_at)->format('Y-m-d')),
                'from' => $format->time($shift->started_at),
                'to' => $format->time($shift->ended_at),
                'guard' => self::userName($shift->guardUser),
                'closed_by_text' => Labels::closedBy($shift->closed_by),
            ], $summary->shifts),
            'flags' => self::labelled($summary->flagCounts()),
            'flagged_scans' => array_map(fn (Scan $scan): array => [
                'day' => LocalFormat::shortDate($format->local($scan->scanned_at)->format('Y-m-d')),
                ...$content->outsideScan($scan),
            ], $flagged),
            'flagged_more' => count($summary->flaggedScans) - count($flagged),
            'incident_categories' => $content->incidentCategories(),
            'incidents' => array_map(fn (Incident $incident): array => [
                'day' => LocalFormat::shortDate($format->local($incident->occurred_at)->format('Y-m-d')),
                ...$content->incident($incident),
            ], $summary->incidents),
            'chain' => self::chain($summary),
            'photoLinkDays' => Retention::days($summary->site),
            'generated_at' => $format->dateOf($summary->generatedAt) . ' ' . $format->time($summary->generatedAt),
        ];
    }

    /** @return array<string, int|string> */
    private function totals(): array
    {
        $summary = $this->summary;
        $flags = $summary->flagCounts();
        $rate = $summary->completionRate();

        return [
            'expected' => $summary->expectedCount(),
            'completed' => $summary->count(RoundOutcome::Completed),
            'incomplete' => $summary->count(RoundOutcome::Incomplete),
            'missed' => $summary->count(RoundOutcome::Missed),
            'pending' => $summary->count(RoundOutcome::Pending),
            'incidents' => count($summary->incidents),
            'manual' => $flags['manual'] ?? 0,
            'suspicious' => count(array_filter(
                $summary->flaggedScans,
                fn (Scan $scan): bool => array_intersect($scan->flags, Labels::SUSPICIOUS_FLAGS) !== [],
            )),
            'rate' => $rate === null ? '–' : self::percent($rate),
        ];
    }

    /** @return list<array<string, int|string>> the period's days, each with its counts */
    private function days(): array
    {
        $byDay = [];
        foreach ($this->summary->rounds as $row) {
            $byDay[$row->startDay][] = $row->outcome;
        }
        $days = [];
        foreach (LocalFormat::days($this->summary->fromDate, $this->summary->toDate) as $day) {
            $outcomes = $byDay[$day] ?? [];
            $count = fn (RoundOutcome $outcome): int => count(array_filter($outcomes, fn (RoundOutcome $o): bool => $o === $outcome));
            $expected = count($outcomes) - $count(RoundOutcome::Upcoming);
            $days[] = [
                'label' => LocalFormat::shortDate($day),
                'weekday' => LocalFormat::weekdayShort($day),
                'expected' => $expected,
                'completed' => $count(RoundOutcome::Completed),
                'incomplete' => $count(RoundOutcome::Incomplete),
                'missed' => $count(RoundOutcome::Missed),
                'rate' => $expected === 0 ? '–' : self::percent($count(RoundOutcome::Completed) / $expected),
            ];
        }

        return $days;
    }

    /** @return array<string, mixed> */
    private function round(RoundRow $row): array
    {
        $round = $row->round;
        $scans = $row->scans;
        $points = [];
        $done = 0;
        foreach ($row->version === null ? [] : $row->version->points->sortBy('position') as $point) {
            $scan = collect($scans)->first(fn (Scan $scan): bool => $scan->checkpoint_id === $point->checkpoint_id);
            $done += $scan === null ? 0 : 1;
            $points[] = $scan === null
                ? ['name' => $this->checkpointName($point->checkpoint_id), 'time' => null, 'method' => null, 'flag' => 'absent', 'flag_text' => 'hiányzik']
                : $this->point($scan);
        }
        // A scan of the round that is not a point of the version (an unknown tag).
        foreach ($scans as $scan) {
            if ($scan->checkpoint_id === null) {
                $points[] = $this->point($scan);
            }
        }
        $last = collect($scans)->max(fn (Scan $scan): int => Time::milliseconds($scan->scanned_at));
        $notes = [];
        foreach (self::pointFlagCounts($scans) as $flag => $count) {
            $notes[] = "{$count} " . Labels::flag($flag);
        }

        return [
            'time' => $this->format->time($row->scheduledAt),
            'route' => $this->routes->get($row->routeId)->name ?? '–',
            'status' => $row->outcome->value,
            'status_text' => Labels::outcome($row->outcome),
            'start' => $this->format->time($round?->started_at),
            'duration_min' => $round?->started_at === null || $last === null
                ? null
                : (int) round(($last - Time::milliseconds($round->started_at)) / 60000),
            'done' => $done,
            'total' => $row->version === null ? 0 : $row->version->points->count(),
            'note' => $notes === [] ? null : implode(', ', $notes),
            'route_version' => $row->version?->version,
            'points' => $points,
            'flagged' => collect($points)->contains(fn (array $point): bool => $point['flag'] !== null && $point['flag'] !== 'absent'),
        ];
    }

    /** @return array{name: string, time: string|null, method: string, flag: string|null, flag_text: string|null} */
    private function point(Scan $scan): array
    {
        $flags = self::pointFlags($scan);

        return [
            'name' => $scan->checkpoint_id === null ? 'Ismeretlen címke' : $this->checkpointName($scan->checkpoint_id),
            'time' => $this->format->time($scan->scanned_at),
            'method' => Labels::method($scan->method),
            'flag' => $flags === [] ? null : self::FLAG_STYLES[$flags[0]] ?? 'warning',
            'flag_text' => $flags === [] ? null : implode(', ', array_map(Labels::flag(...), $flags)),
        ];
    }

    /** @return array<string, mixed> */
    private function outsideScan(Scan $scan): array
    {
        return [...$this->point($scan), 'flag_text' => implode(', ', array_map(Labels::flag(...), self::sortedFlags($scan->flags)))];
    }

    /** @return array<string, mixed> */
    private function incident(Incident $incident): array
    {
        $photos = $this->media->filter(fn (Media $media): bool => ($media->owner_type === Incident::class && $media->owner_id === $incident->id)
            || ($incident->scan_id !== null && $media->owner_type === Scan::class && $media->owner_id === $incident->scan_id));
        $links = [];
        foreach ($photos as $photo) {
            $url = $this->embed === null ? SignedUrls::photo($photo, $this->summary->site) : ($this->embed)($photo);
            if ($url !== null) {
                $links[] = ['url' => $url, 'thumb_url' => $url];
            }
        }

        return [
            'time' => $this->format->time($incident->occurred_at),
            'category' => Labels::category($incident->category),
            'place' => $incident->nearest_checkpoint_id === null ? null : $this->checkpointName($incident->nearest_checkpoint_id),
            'accuracy' => $incident->accuracy_dm === null ? null : (int) round($incident->accuracy_dm / 10),
            'note' => $incident->note,
            'manual_point' => $incident->scan_id !== null,
            'photos' => $links,
            'photos_missing' => $photos->whereNull('uploaded_at')->count(),
        ];
    }

    /** @return list<array{label: string, count: int}> */
    private function incidentCategories(): array
    {
        $counts = [];
        foreach ($this->summary->incidentsByCategory() as $category => $count) {
            $counts[] = ['label' => Labels::category(IncidentCategory::from($category)), 'count' => $count];
        }

        return $counts;
    }

    private function checkpointName(string $id): string
    {
        return $this->checkpoints->get($id)->name ?? 'Törölt pont';
    }

    private function showsRoutes(): bool
    {
        return collect($this->summary->rounds)->pluck('routeId')->unique()->count() > 1;
    }

    /** The guard whose PIN login closed the shift: the next shift of the phone. */
    private function handoverGuard(Shift $shift): ?string
    {
        if ($shift->ended_at === null || $shift->closed_by !== ShiftClosedBy::Handover) {
            return null;
        }
        $next = Shift::query()
            ->where('device_id', $shift->device_id)
            ->where('started_at', '>=', $shift->ended_at->format(Time::DATABASE_FORMAT))
            ->whereKeyNot($shift->id)
            ->orderBy('started_at')
            ->first();

        return $next === null ? null : self::userName($next->guardUser()->withTrashed()->first());
    }

    /** When the shift's late entries arrived (late_sync: more than 12 hours after the close). */
    private function lateSyncAt(Shift $shift, DateTimeInterface $end): ?string
    {
        $from = ChainEntry::query()->whereKey($shift->chain_entry_id)->value('seq');
        $latest = ChainEntry::query()
            ->where('device_id', $shift->device_id)
            ->where('seq', '>=', $from ?? 1)
            ->where('verify_status', VerifyStatus::Ok->value)
            ->get(['id', 'flags', 'received_at'])
            ->filter(fn (ChainEntry $entry): bool => in_array(ChainFlag::LateSync->value, $entry->flags, true))
            ->max(fn (ChainEntry $entry): int => Time::milliseconds($entry->received_at));
        if ($latest === null) {
            return null;
        }
        $at = Time::fromMilliseconds($latest);
        $sameDay = $this->format->local($at)->format('Y-m-d') === $this->format->local($end)->format('Y-m-d');

        return $sameDay ? (string) $this->format->time($at) : LocalFormat::shortDate($this->format->local($at)->format('Y-m-d')) . ' ' . $this->format->time($at);
    }

    /** @return list<string> */
    private static function incomplete(Summary $summary): array
    {
        return array_map(Labels::incompleteReason(...), $summary->incompleteReasons);
    }

    /** @return array{ok: bool, text: string, diagnostics: int} */
    private static function chain(Summary $summary): array
    {
        $count = $summary->badEntryCount();
        $parts = [];
        foreach ($summary->badEntries as $status => $entries) {
            $parts[] = "{$entries} " . Labels::verifyStatus(VerifyStatus::from($status));
        }

        return [
            'ok' => $count === 0 && $summary->diagnostics === 0,
            'text' => $count === 0 ? 'rendben' : "{$count} hibás bejegyzés (" . implode(', ', $parts) . ')',
            'diagnostics' => $summary->diagnostics,
        ];
    }

    /**
     * The flags a point's row shows, most serious first: out_of_round needs no saying in a round, and late_sync is said
     * once at the top.
     *
     * @return list<string>
     */
    private static function pointFlags(Scan $scan): array
    {
        return array_values(array_diff(self::sortedFlags($scan->flags), ['out_of_round', ChainFlag::LateSync->value]));
    }

    /**
     * @param  list<Scan>  $scans
     * @return array<string, int>
     */
    private static function pointFlagCounts(array $scans): array
    {
        $counts = [];
        foreach ($scans as $scan) {
            foreach (self::pointFlags($scan) as $flag) {
                $counts[$flag] = ($counts[$flag] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param  list<string>  $flags
     * @return list<string>
     */
    private static function sortedFlags(array $flags): array
    {
        $order = array_flip(array_keys(Labels::FLAGS));
        usort($flags, fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        return $flags;
    }

    /**
     * @param  array<string, int>  $flagCounts
     * @return list<array{label: string, count: int}>
     */
    private static function labelled(array $flagCounts): array
    {
        $labelled = [];
        foreach (self::sortedFlags(array_keys($flagCounts)) as $flag) {
            $labelled[] = ['label' => Labels::flag($flag), 'count' => $flagCounts[$flag]];
        }

        return $labelled;
    }

    private static function percent(float $rate): string
    {
        return (int) round($rate * 100) . '%';
    }

    private static function userName(?User $user): string
    {
        return $user->name ?? 'Ismeretlen';
    }
}
