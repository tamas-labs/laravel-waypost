<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Waypost\Enums\ChainEntryType;
use Waypost\Enums\ChainFlag;
use Waypost\Enums\IncidentCategory;
use Waypost\Enums\MediaOwnerType;
use Waypost\Enums\RoundStatus;
use Waypost\Enums\ShiftClosedBy;
use Waypost\Enums\VerifyStatus;
use Waypost\Models\ChainEntry;
use Waypost\Models\Device;
use Waypost\Models\DeviceDiagnostic;
use Waypost\Models\Incident;
use Waypost\Models\Media;
use Waypost\Models\Round;
use Waypost\Models\Route;
use Waypost\Models\Scan;
use Waypost\Models\Schedule;
use Waypost\Models\Shift;
use Waypost\Models\Site;
use Waypost\Reports\IncompleteReason;
use Waypost\Reports\RoundOutcome;
use Waypost\Reports\RoundRow;
use Waypost\Reports\Summarizer;
use Waypost\Reports\Summary;
use Waypost\Support\Table;
use Waypost\Support\Time;

pest()->use(RefreshDatabase::class);

function summaryOf(Site $site, string $from, string $to, string $now): Summary
{
    return app(Summarizer::class)->period($site, $from, $to, CarbonImmutable::parse($now));
}

/** @return list<array{string, string, string}> start day, scheduled_at, outcome of each row */
function rowsOf(Summary $summary): array
{
    return array_map(fn (RoundRow $row): array => [$row->startDay, Time::iso($row->scheduledAt), $row->outcome->value], $summary->rounds);
}

it('keeps a window across midnight on its start day, with its shifts and incidents', function (): void {
    // Monday only: 22:00, 00:00, 02:00, 04:00 (CET, UTC+1).
    $fixture = patrolledSite(['weekdays' => 1, 'start_times' => ['22:00', '00:00', '02:00', '04:00'], 'time_limit_min' => 20]);
    $phone = $fixture['phone'];
    assert($phone instanceof Device);
    [$first, $second] = $fixture['points'];

    $evening = shiftOf($phone, '2026-11-02T20:50:00.000Z', '2026-11-03T00:00:00.000Z', ShiftClosedBy::Handover);
    roundOf($evening, $fixture, '2026-11-02T21:00:00.000Z', RoundStatus::Completed, [0 => '2026-11-02T21:02:00.000Z', 1 => '2026-11-02T21:09:00.000Z']);
    roundOf($evening, $fixture, '2026-11-02T23:00:00.000Z', RoundStatus::Incomplete, [1 => '2026-11-02T23:04:00.000Z']);
    // Taken over after midnight: still Monday's night.
    $night = shiftOf($phone, '2026-11-03T00:00:00.000Z', '2026-11-03T06:00:00.000Z');
    roundOf($night, $fixture, '2026-11-03T01:00:00.000Z', RoundStatus::Completed, [0 => '2026-11-03T01:03:00.000Z', 1 => '2026-11-03T01:11:00.000Z']);
    $incident = Incident::factory()->create(['shift_id' => $night->id, 'category' => IncidentCategory::OpenDoor, 'occurred_at' => Time::fromIso('2026-11-03T02:10:00.000Z')]);
    $phone->update(['last_seen_at' => Time::fromIso('2026-11-03T06:30:00.000Z')]);

    $monday = summaryOf($fixture['site'], '2026-11-02', '2026-11-02', '2026-11-03T09:00:00.000Z');

    expect(rowsOf($monday))->toBe([
        ['2026-11-02', '2026-11-02T21:00:00.000Z', 'completed'],
        ['2026-11-02', '2026-11-02T23:00:00.000Z', 'incomplete'],
        ['2026-11-02', '2026-11-03T01:00:00.000Z', 'completed'],
        ['2026-11-02', '2026-11-03T03:00:00.000Z', 'missed'],
    ])
        ->and($monday->periodStart->toIso8601ZuluString())->toBe('2026-11-01T23:00:00Z')
        ->and($monday->periodEnd->toIso8601ZuluString())->toBe('2026-11-02T23:00:00Z')
        // The last round's deadline: 04:00 + 20 minutes.
        ->and(Time::iso($monday->dataEnd))->toBe('2026-11-03T03:20:00.000Z')
        ->and([$monday->expectedCount(), $monday->actualCount(), $monday->completionRate()])->toBe([4, 3, 0.5])
        ->and([$monday->count(RoundOutcome::Completed), $monday->count(RoundOutcome::Incomplete), $monday->count(RoundOutcome::Missed)])->toBe([2, 1, 1])
        ->and($monday->rounds[1]->missingCheckpointIds)->toBe([$first->id])
        ->and($monday->rounds[3]->missingCheckpointIds)->toBe([$first->id, $second->id])
        ->and($monday->rounds[3]->round)->toBeNull()
        ->and($monday->rounds[3]->version?->id)->toBe($fixture['version']->id)
        ->and(array_map(fn (Shift $shift): string => $shift->id, $monday->shifts))->toBe([$evening->id, $night->id])
        ->and(array_map(fn (Incident $incident): string => $incident->id, $monday->incidents))->toBe([$incident->id])
        ->and($monday->incidentsByCategory())->toBe(['open_door' => 1])
        ->and($monday->deviceIds)->toBe([$phone->id])
        ->and($monday->incompleteReasons)->toBe([]);

    $tuesday = summaryOf($fixture['site'], '2026-11-03', '2026-11-03', '2026-11-04T09:00:00.000Z');

    expect($tuesday->rounds)->toBe([])
        ->and($tuesday->shifts)->toBe([])
        ->and($tuesday->incidents)->toBe([]);
});

it('expands the autumn DST night into 13 rounds and the spring one into 12', function (): void {
    // Every day, 20:00–08:00 hourly, no time limit: the deadline is the next round.
    $fixture = patrolledSite(['weekdays' => 127, 'mode' => 'interval', 'start_times' => null, 'interval_from' => '20:00', 'interval_to' => '08:00', 'interval_every_min' => 60, 'time_limit_min' => null]);
    $phone = $fixture['phone'];
    assert($phone instanceof Device);
    $phone->update(['last_seen_at' => Time::fromIso('2026-10-25T09:00:00.000Z')]);

    // Saturday 2026-10-24: the phone does all 13 rounds of the night the clocks go back.
    $shift = shiftOf($phone, '2026-10-24T17:55:00.000Z', '2026-10-25T08:00:00.000Z');
    // 20:00 … 02:00 CEST, then 03:00 … 08:00 CET.
    $expected = [];
    foreach (['18', '19', '20', '21', '22', '23'] as $hour) {
        $expected[] = "2026-10-24T{$hour}:00:00.000Z";
    }
    foreach (['00', '02', '03', '04', '05', '06', '07'] as $hour) {
        $expected[] = "2026-10-25T{$hour}:00:00.000Z";
    }
    foreach ($expected as $at) {
        $time = Time::fromIso($at);
        roundOf($shift, $fixture, $at, RoundStatus::Completed, [0 => Time::iso($time->addMinutes(2)), 1 => Time::iso($time->addMinutes(9))]);
    }
    $expected = array_map(fn (string $at): array => ['2026-10-24', $at, 'completed'], $expected);
    $autumn = summaryOf($fixture['site'], '2026-10-24', '2026-10-24', '2026-10-25T08:00:00.000Z');

    expect(rowsOf($autumn))->toBe($expected)
        // 02:00 CEST (00:00Z) and 03:00 CET (02:00Z) are two hours apart.
        ->and(Time::iso($autumn->rounds[6]->scheduledAt))->toBe('2026-10-25T00:00:00.000Z')
        ->and(Time::iso($autumn->rounds[7]->scheduledAt))->toBe('2026-10-25T02:00:00.000Z')
        ->and($autumn->completionRate())->toBe(1.0)
        // The last round, without a time limit: 08:00 + 60 minutes.
        ->and(Time::iso($autumn->dataEnd))->toBe('2026-10-25T08:00:00.000Z')
        ->and($autumn->incompleteReasons)->toBe([]);

    // Sunday 2026-10-25 has 25 hours.
    $sunday = summaryOf($fixture['site'], '2026-10-25', '2026-10-25', '2026-10-27T09:00:00.000Z');
    expect(Time::iso($sunday->periodStart))->toBe('2026-10-24T22:00:00.000Z')
        ->and(Time::iso($sunday->periodEnd))->toBe('2026-10-25T23:00:00.000Z');

    // Saturday 2026-03-28: the clocks go forward, 02:00 does not exist; no shift, so 12 missed rounds.
    $phone->update(['last_seen_at' => Time::fromIso('2026-03-29T09:00:00.000Z')]);
    $spring = summaryOf($fixture['site'], '2026-03-28', '2026-03-28', '2026-03-29T09:00:00.000Z');

    expect($spring->rounds)->toHaveCount(0);

    // The schedule existed only from 2026-10-01: move it back.
    foreach (['schedules', 'routes', 'route_versions'] as $table) {
        DB::table(Table::name($table))->update(['created_at' => '2026-01-05 08:00:00.000']);
    }
    $spring = summaryOf($fixture['site'], '2026-03-28', '2026-03-28', '2026-03-29T09:00:00.000Z');

    expect($spring->rounds)->toHaveCount(12)
        ->and($spring->count(RoundOutcome::Missed))->toBe(12)
        // 01:00 CET (00:00Z), then 03:00 CEST (01:00Z).
        ->and(Time::iso($spring->rounds[5]->scheduledAt))->toBe('2026-03-29T00:00:00.000Z')
        ->and(Time::iso($spring->rounds[6]->scheduledAt))->toBe('2026-03-29T01:00:00.000Z')
        ->and($spring->incompleteReasons)->toBe([]);
});

it('counts the night without a phone working as missed, and incomplete only while the phone may still sync', function (): void {
    $fixture = patrolledSite(['weekdays' => 127, 'start_times' => ['20:00', '22:00'], 'time_limit_min' => 30]);
    $phone = $fixture['phone'];
    assert($phone instanceof Device);

    // No shift on Tuesday night, and the phone has not been heard of since the evening.
    $phone->update(['last_seen_at' => Time::fromIso('2026-11-03T18:00:00.000Z')]);
    $silent = summaryOf($fixture['site'], '2026-11-03', '2026-11-03', '2026-11-04T09:00:00.000Z');

    expect(rowsOf($silent))->toBe([
        ['2026-11-03', '2026-11-03T19:00:00.000Z', 'missed'],
        ['2026-11-03', '2026-11-03T21:00:00.000Z', 'missed'],
    ])
        ->and($silent->actualCount())->toBe(0)
        ->and($silent->completionRate())->toBe(0.0)
        ->and($silent->incompleteReasons)->toBe([IncompleteReason::NotSynced]);

    // It synced after the night: the rounds really were missed.
    $phone->update(['last_seen_at' => Time::fromIso('2026-11-04T06:00:00.000Z')]);
    expect(summaryOf($fixture['site'], '2026-11-03', '2026-11-03', '2026-11-04T09:00:00.000Z')->incompleteReasons)->toBe([]);

    // Revoked before the night: its data cannot be about it. A site without a phone at all is complete.
    $phone->update(['last_seen_at' => Time::fromIso('2026-11-03T18:00:00.000Z'), 'revoked_at' => Time::fromIso('2026-11-02T12:00:00.000Z')]);
    $noPhone = summaryOf($fixture['site'], '2026-11-03', '2026-11-03', '2026-11-04T09:00:00.000Z');

    expect($noPhone->deviceIds)->toBe([])
        ->and($noPhone->count(RoundOutcome::Missed))->toBe(2)
        ->and($noPhone->incompleteReasons)->toBe([]);

    // Revoked during the night: incomplete until its last upload is closed.
    $phone->update(['revoked_at' => Time::fromIso('2026-11-03T20:00:00.000Z')]);
    expect(summaryOf($fixture['site'], '2026-11-03', '2026-11-03', '2026-11-04T09:00:00.000Z')->incompleteReasons)->toBe([IncompleteReason::NotSynced]);
    $phone->update(['upload_closed_at' => Time::fromIso('2026-11-03T20:05:00.000Z')]);
    expect(summaryOf($fixture['site'], '2026-11-03', '2026-11-03', '2026-11-04T09:00:00.000Z')->incompleteReasons)->toBe([]);
});

it('leaves a time before its deadline upcoming and tells a round still open after it', function (): void {
    $fixture = patrolledSite(['weekdays' => 127, 'start_times' => ['20:00', '22:00'], 'time_limit_min' => 30]);
    $phone = $fixture['phone'];
    assert($phone instanceof Device);
    $shift = shiftOf($phone, '2026-11-03T18:55:00.000Z');
    roundOf($shift, $fixture, '2026-11-03T19:00:00.000Z', RoundStatus::InProgress, [0 => '2026-11-03T19:03:00.000Z']);

    // 21:10 local: the 20:00 round is still running, 22:00 has not come.
    $during = summaryOf($fixture['site'], '2026-11-03', '2026-11-03', '2026-11-03T19:10:00.000Z');
    expect(rowsOf($during))->toBe([
        ['2026-11-03', '2026-11-03T19:00:00.000Z', 'pending'],
        ['2026-11-03', '2026-11-03T21:00:00.000Z', 'upcoming'],
    ])
        ->and($during->expectedCount())->toBe(1)
        ->and($during->incompleteReasons)->toBe([IncompleteReason::NotSynced]);

    // The next morning, the close of 20:00 has still not arrived, though the phone synced.
    $phone->update(['last_seen_at' => Time::fromIso('2026-11-04T06:00:00.000Z')]);
    $after = summaryOf($fixture['site'], '2026-11-03', '2026-11-03', '2026-11-04T09:00:00.000Z');
    expect(rowsOf($after)[1][2])->toBe('missed')
        ->and($after->incompleteReasons)->toBe([IncompleteReason::PendingRounds]);
});

it('expects a schedule only while it, its route and a trained version existed and are active', function (): void {
    $fixture = patrolledSite(['weekdays' => 127, 'start_times' => ['20:00', '22:00', '00:00'], 'time_limit_min' => 30]);
    $phone = $fixture['phone'];
    assert($phone instanceof Device);
    $phone->update(['last_seen_at' => Time::fromIso('2026-11-10T06:00:00.000Z')]);
    $site = $fixture['site'];

    // Created at 21:30 local on 2026-11-03: only 22:00 and 00:00 that night.
    $fixture['schedule']->forceFill(['created_at' => Time::fromIso('2026-11-03T20:30:00.000Z')])->save();
    expect(array_column(rowsOf(summaryOf($site, '2026-11-03', '2026-11-03', '2026-11-04T09:00:00.000Z')), 1))
        ->toBe(['2026-11-03T21:00:00.000Z', '2026-11-03T23:00:00.000Z']);

    // The route deleted on 2026-11-04 at 21:00 local: that night only 20:00; the night before is unchanged.
    $fixture['route']->forceFill(['deleted_at' => Time::fromIso('2026-11-04T20:00:00.000Z')])->save();
    expect(array_column(rowsOf(summaryOf($site, '2026-11-03', '2026-11-04', '2026-11-05T09:00:00.000Z')), 1))
        ->toBe(['2026-11-03T21:00:00.000Z', '2026-11-03T23:00:00.000Z', '2026-11-04T19:00:00.000Z']);

    // An inactive schedule expects nothing, but a round the phone did is still there.
    $fixture['route']->forceFill(['deleted_at' => null])->save();
    $fixture['schedule']->update(['active' => false]);
    $shift = shiftOf($phone, '2026-11-05T18:55:00.000Z', '2026-11-05T23:30:00.000Z');
    roundOf($shift, $fixture, '2026-11-05T21:00:00.000Z', RoundStatus::Completed, [0 => '2026-11-05T21:02:00.000Z', 1 => '2026-11-05T21:09:00.000Z']);
    $inactive = summaryOf($site, '2026-11-05', '2026-11-05', '2026-11-06T09:00:00.000Z');

    expect(rowsOf($inactive))->toBe([['2026-11-05', '2026-11-05T21:00:00.000Z', 'completed']])
        ->and($inactive->rounds[0]->expected)->toBeFalse()
        ->and($inactive->rounds[0]->round)->not->toBeNull();
});

it('lists the flagged scans, the bad chain entries and what is still to come', function (): void {
    $fixture = patrolledSite(['weekdays' => 127, 'start_times' => ['20:00'], 'time_limit_min' => 30]);
    $phone = $fixture['phone'];
    assert($phone instanceof Device);
    $phone->update(['last_seen_at' => Time::fromIso('2026-11-04T06:00:00.000Z')]);
    $shift = shiftOf($phone, '2026-11-03T18:55:00.000Z', '2026-11-03T22:00:00.000Z');
    $round = roundOf($shift, $fixture, '2026-11-03T19:00:00.000Z', RoundStatus::Completed);
    $tooFast = scanOf($phone, $round, $fixture['points'][0], '2026-11-03T19:02:00.000Z', [ChainFlag::TimeSuspect->value]);
    scanOf($phone, $round, $fixture['points'][1], '2026-11-03T19:03:00.000Z');
    // Outside the round, during the shift; and one on another evening.
    $outside = scanOf($phone, null, null, '2026-11-03T20:30:00.000Z', ['unknown_tag', 'out_of_round']);
    scanOf($phone, null, null, '2026-11-04T20:30:00.000Z', ['out_of_round']);
    ChainEntry::factory()->create(['device_id' => $phone->id, 'verify_status' => VerifyStatus::BadHmac, 'device_time' => Time::fromIso('2026-11-03T20:00:00.000Z')]);
    ChainEntry::factory()->create(['device_id' => $phone->id, 'verify_status' => VerifyStatus::Fork, 'device_time' => Time::fromIso('2026-11-03T20:01:00.000Z')]);
    ChainEntry::factory()->create(['device_id' => $phone->id, 'verify_status' => VerifyStatus::BadHmac, 'device_time' => Time::fromIso('2026-11-05T20:00:00.000Z')]);
    DeviceDiagnostic::factory()->create(['device_id' => $phone->id, 'occurred_at' => Time::fromIso('2026-11-03T20:02:00.000Z')]);

    $summary = summaryOf($fixture['site'], '2026-11-03', '2026-11-03', '2026-11-04T09:00:00.000Z');

    expect(array_map(fn (Scan $scan): string => $scan->id, $summary->flaggedScans))->toBe([$tooFast->id, $outside->id])
        ->and($summary->flagCounts())->toBe(['out_of_round' => 1, 'time_suspect' => 1, 'unknown_tag' => 1])
        ->and($summary->badEntries)->toBe(['bad_hmac' => 1, 'fork' => 1])
        ->and($summary->badEntryCount())->toBe(2)
        ->and($summary->diagnostics)->toBe(1)
        ->and($summary->incompleteReasons)->toBe([]);

    // A photo not uploaded yet, and an entry that waits for configuration.
    Media::factory()->create(['owner_type' => MediaOwnerType::Scan->stored(), 'owner_id' => $outside->id, 'device_id' => $phone->id]);
    ChainEntry::factory()->create(['device_id' => $phone->id, 'type' => ChainEntryType::Scan, 'device_time' => Time::fromIso('2026-11-03T20:40:00.000Z'), 'projected_at' => null]);

    expect(summaryOf($fixture['site'], '2026-11-03', '2026-11-03', '2026-11-04T09:00:00.000Z')->incompleteReasons)
        ->toBe([IncompleteReason::PendingEntries, IncompleteReason::MissingPhotos]);
});
