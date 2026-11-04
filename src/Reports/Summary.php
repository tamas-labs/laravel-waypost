<?php

declare(strict_types=1);

namespace Waypost\Reports;

use Carbon\CarbonImmutable;
use Waypost\Models\Incident;
use Waypost\Models\Scan;
use Waypost\Models\Shift;
use Waypost\Models\Site;

/**
 * The data of a site's report for a period of local days (Summarizer::period()) or for a shift (Summarizer::shift()):
 * the rounds (expected and actual), the shifts, the flags and incidents, the state of the chain, and whether the data
 * may still be incomplete.
 */
final readonly class Summary
{
    /**
     * @param  list<RoundRow>  $rounds  in time order
     * @param  list<Shift>  $shifts  whose day is in the period, in time order
     * @param  list<Incident>  $incidents  of those shifts, in time order
     * @param  list<Scan>  $flaggedScans  of the period with a flag (the app's or the server's), in time order
     * @param  list<Scan>  $scansOutsideRounds  of the period without a round, in time order
     * @param  array<string, int>  $badEntries  chain entries of the period not ok, by verify_status
     * @param  list<string>  $deviceIds  the site's phones the period's data comes from
     * @param  list<IncompleteReason>  $incompleteReasons
     */
    public function __construct(
        public Site $site,
        /** First and last local day (Y-m-d), inclusive; of a shift: the local dates of its start and end. */
        public string $fromDate,
        public string $toDate,
        /** Local midnight starting the first day and after the last one, in UTC; of a shift: its start and end. */
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        /** When the period's data ends: periodEnd, or the latest deadline of its rounds if later. */
        public CarbonImmutable $dataEnd,
        public CarbonImmutable $generatedAt,
        public array $rounds,
        public array $shifts,
        public array $incidents,
        public array $flaggedScans,
        public array $scansOutsideRounds,
        public array $badEntries,
        /** The phones' reports of rejected entries (device_diagnostics) in the period. */
        public int $diagnostics,
        public array $deviceIds,
        public array $incompleteReasons,
    ) {
    }

    public function count(RoundOutcome $outcome): int
    {
        return count(array_filter($this->rounds, fn (RoundRow $row): bool => $row->outcome === $outcome));
    }

    /** The rounds that were due: every row whose deadline has passed or that has a round. */
    public function expectedCount(): int
    {
        return count($this->rounds) - $this->count(RoundOutcome::Upcoming);
    }

    /** The rounds the phone opened. */
    public function actualCount(): int
    {
        return count(array_filter($this->rounds, fn (RoundRow $row): bool => $row->round !== null));
    }

    /** Completed / expected, null without an expected round. */
    public function completionRate(): ?float
    {
        $expected = $this->expectedCount();

        return $expected === 0 ? null : $this->count(RoundOutcome::Completed) / (float) $expected;
    }

    /** @return array<string, int> by category, in the order of first occurrence */
    public function incidentsByCategory(): array
    {
        $counts = [];
        foreach ($this->incidents as $incident) {
            $counts[$incident->category->value] = ($counts[$incident->category->value] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<string, int> scans by flag; a scan with two flags counts under both */
    public function flagCounts(): array
    {
        $counts = [];
        foreach ($this->flaggedScans as $scan) {
            foreach ($scan->flags as $flag) {
                $counts[$flag] = ($counts[$flag] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }

    public function badEntryCount(): int
    {
        return array_sum($this->badEntries);
    }

    public function isIncomplete(): bool
    {
        return $this->incompleteReasons !== [];
    }
}
