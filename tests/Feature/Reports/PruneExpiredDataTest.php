<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Waypost\Enums\MediaOwnerType;
use Waypost\Enums\ReportFrequency;
use Waypost\Jobs\PruneExpiredData;
use Waypost\Models\ChainEntry;
use Waypost\Models\Checkpoint;
use Waypost\Models\Device;
use Waypost\Models\DeviceDiagnostic;
use Waypost\Models\Incident;
use Waypost\Models\Media;
use Waypost\Models\NoDataAlert;
use Waypost\Models\Report;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Round;
use Waypost\Models\Scan;
use Waypost\Models\Shift;
use Waypost\Models\User;
use Waypost\Support\Time;
use Waypost\Support\Uuid;
use Waypost\Tests\Fixtures\ChainBuilder;

use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    Storage::fake('local');
});

/**
 * Pushes a patrol at the time, as the phone does: a shift with a round of both points, an incident with an uploaded
 * photo, a scan outside the round, the close; then a diagnostic report.
 *
 * @param  array{points: list<Checkpoint>, version: \Waypost\Models\RouteVersion, schedule: \Waypost\Models\Schedule, phone: Device|null}  $fixture
 * @return array{shift_id: string, photo_id: string, entries: list<array<string, mixed>>}
 */
function pushedPatrol(array $fixture, ChainBuilder $chain, string $at): array
{
    $phone = $fixture['phone'] ?? throw new LogicException('The site has its phone.');
    $start = CarbonImmutable::parse($at);
    travelTo($start->addHours(2));
    $time = fn (int $minutes): array => ['device_time' => Time::iso($start->addMinutes($minutes))];
    $started = $chain->shiftStarted(User::factory()->create(), $time(0));
    $shiftId = $started['payload']['shift_id'];
    $roundId = Uuid::v7();
    $bytes = jpegBytes($at);
    $photoId = Uuid::v7();
    $entries = [$started];
    $entries[] = $chain->entry('round_opened', ['round_id' => $roundId, 'shift_id' => $shiftId, 'route_version_id' => $fixture['version']->id,
        'schedule_id' => $fixture['schedule']->id, 'scheduled_at' => Time::iso($start->addMinutes(5)), 'time_limit_min' => 30], $time(5));
    $entries[] = $chain->scan($roundId, $fixture['points'][0]->id, [], $time(7));
    $entries[] = $chain->scan($roundId, $fixture['points'][1]->id, [], $time(12));
    $entries[] = $chain->entry('round_closed', ['round_id' => $roundId, 'status' => 'completed', 'finished_at' => Time::iso($start->addMinutes(12))], $time(12));
    $entries[] = $incident = $chain->incident($shiftId, ['media' => [['id' => $photoId, 'sha256' => hash('sha256', $bytes)]]], $time(30));
    $entries[] = $chain->scan(null, $fixture['points'][0]->id, [], $time(40));
    $entries[] = $chain->shiftClosed($shiftId, 'guard', $time(60));
    pushAs($phone, $entries)->assertOk();
    uploadMedia($photoId, ['sha256' => hash('sha256', $bytes), 'owner_type' => 'incident', 'owner_id' => $incident['payload']['incident_id'],
        'taken_at' => Time::iso($start->addMinutes(30))], ['Authorization' => 'Bearer ' . deviceToken($phone)], $bytes)->assertOk();
    DeviceDiagnostic::factory()->create(['device_id' => $phone->id, 'occurred_at' => $start, 'received_at' => $start->addHours(2)]);

    return ['shift_id' => $shiftId, 'photo_id' => $photoId, 'entries' => $entries];
}

/** @return array<string, int> the rows of a phone's raw data */
function rawData(Device $phone): array
{
    $shiftIds = Shift::query()->where('device_id', $phone->id)->pluck('id');
    $entryIds = ChainEntry::query()->where('device_id', $phone->id)->pluck('id');

    return [
        'chain_entries' => $entryIds->count(),
        'shifts' => $shiftIds->count(),
        'rounds' => Round::query()->whereIn('shift_id', $shiftIds)->count(),
        'scans' => Scan::query()->whereIn('chain_entry_id', $entryIds)->count(),
        'incidents' => Incident::query()->whereIn('shift_id', $shiftIds)->count(),
        'media' => Media::query()->where('device_id', $phone->id)->count(),
        'diagnostics' => DeviceDiagnostic::query()->where('device_id', $phone->id)->count(),
    ];
}

function pruneExpiredData(string $now): void
{
    travelTo(CarbonImmutable::parse($now));
    app()->call([new PruneExpiredData(), 'handle']);
}

it('deletes the raw data older than 31 days, and keeps the configuration and the chain\'s end', function (): void {
    $fixture = patrolledSite(['weekdays' => 127, 'start_times' => ['20:00'], 'time_limit_min' => 30]);
    $phone = $fixture['phone'] ?? throw new LogicException('The site has its phone.');
    $site = $fixture['site'];
    ReportSubscription::factory()->create(['user_id' => supervisorOf($site)->id, 'site_id' => $site->id, 'frequency' => ReportFrequency::Weekly]);
    // A photo of a checkpoint is configuration.
    $checkpointPhoto = Media::factory()->create(['owner_type' => MediaOwnerType::Checkpoint->stored(), 'owner_id' => $fixture['points'][0]->id,
        'path' => 'waypost/media/checkpoint.jpg', 'taken_at' => CarbonImmutable::parse('2026-10-01T12:00:00Z'), 'uploaded_at' => CarbonImmutable::parse('2026-10-01T12:00:00Z')]);
    Storage::disk('local')->put('waypost/media/checkpoint.jpg', 'x');

    $chain = new ChainBuilder($phone);
    $old = pushedPatrol($fixture, $chain, '2026-11-10T19:00:00Z');
    $recent = pushedPatrol($fixture, $chain, '2026-11-20T19:00:00Z');
    $oldReport = Report::factory()->for($site)->create(['type' => ReportFrequency::Weekly, 'period_start' => CarbonImmutable::parse('2026-11-02T23:00:00Z'),
        'period_end' => CarbonImmutable::parse('2026-11-09T23:00:00Z'), 'path' => 'waypost/reports/old.pdf']);
    $recentReport = Report::factory()->for($site)->create(['type' => ReportFrequency::Weekly, 'period_start' => CarbonImmutable::parse('2026-11-16T23:00:00Z'),
        'period_end' => CarbonImmutable::parse('2026-11-23T23:00:00Z'), 'path' => 'waypost/reports/recent.pdf']);
    Storage::disk('local')->put('waypost/reports/old.pdf', '%PDF-old');
    Storage::disk('local')->put('waypost/reports/recent.pdf', '%PDF-recent');
    NoDataAlert::factory()->for($site)->create(['window_day' => '2026-11-10', 'deadline' => CarbonImmutable::parse('2026-11-10T19:30:00Z')]);
    NoDataAlert::factory()->for($site)->create(['window_day' => '2026-11-20', 'deadline' => CarbonImmutable::parse('2026-11-20T19:30:00Z')]);
    $phone->refresh();
    [$lastSeq, $lastHash] = [$phone->last_seq, $phone->last_hash];
    $patrol = ['chain_entries' => 8, 'shifts' => 1, 'rounds' => 1, 'scans' => 3, 'incidents' => 1, 'media' => 1, 'diagnostics' => 1];
    expect(rawData($phone))->toBe(array_map(fn (int $count): int => 2 * $count, $patrol));

    // 31 days before 2026-12-12 12:00 UTC is 2026-11-11 12:00: the first patrol goes, the second stays.
    pruneExpiredData('2026-12-12T12:00:00Z');

    expect(rawData($phone))->toBe($patrol)
        ->and(Shift::query()->whereKey($old['shift_id'])->exists())->toBeFalse()
        ->and(Shift::query()->whereKey($recent['shift_id'])->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists("waypost/media/{$old['photo_id']}.jpg"))->toBeFalse()
        ->and(Storage::disk('local')->exists("waypost/media/{$recent['photo_id']}.jpg"))->toBeTrue()
        ->and(Media::query()->whereKey($checkpointPhoto->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists('waypost/media/checkpoint.jpg'))->toBeTrue()
        // The report rows stay, the old one without its file.
        ->and($oldReport->refresh()->path)->toBeNull()
        ->and(Storage::disk('local')->exists('waypost/reports/old.pdf'))->toBeFalse()
        ->and($recentReport->refresh()->path)->toBe('waypost/reports/recent.pdf')
        ->and(Storage::disk('local')->exists('waypost/reports/recent.pdf'))->toBeTrue()
        ->and(NoDataAlert::query()->pluck('window_day')->all())->toBe(['2026-11-20'])
        ->and($fixture['version']->refresh()->exists)->toBeTrue()
        ->and($fixture['schedule']->refresh()->exists)->toBeTrue();

    // The chain goes on from where it was: the end is kept, the next entry follows it.
    $phone->refresh();
    expect([$phone->last_seq, $phone->last_hash])->toBe([$lastSeq, $lastHash]);
    $next = $chain->shiftStarted(User::factory()->create(), ['device_time' => '2026-12-12T11:00:00.000Z']);
    $response = pushAs($phone, [$next])->assertOk();
    expect($response->json('accepted'))->toBe([$next['id']])
        ->and($response->json('rejected'))->toBe([]);

    // A deleted entry sent again is still the phone's own (its HMAC), not a fork.
    $response = pushAs($phone, [$old['entries'][2]])->assertOk();
    expect($response->json('accepted'))->toBe([$old['entries'][2]['id']])
        ->and($response->json('rejected'))->toBe([]);
});

it('deletes the photo of a checkpoint that never came after 31 days, and keeps that of a deleted one', function (): void {
    $deleted = Checkpoint::factory()->create();
    $deleted->delete();
    $uploaded = CarbonImmutable::parse('2026-10-01T12:00:00Z');
    $photo = fn (string $checkpointId, string $name): Media => Media::factory()->create(['owner_type' => MediaOwnerType::Checkpoint->stored(), 'owner_id' => $checkpointId,
        'path' => "waypost/media/{$name}.jpg", 'taken_at' => $uploaded, 'uploaded_at' => $uploaded]);
    $orphan = $photo(Uuid::v7(), 'orphan');
    $ofDeleted = $photo($deleted->id, 'deleted');
    Storage::disk('local')->put('waypost/media/orphan.jpg', 'x');
    Storage::disk('local')->put('waypost/media/deleted.jpg', 'x');

    pruneExpiredData('2026-11-01T11:59:59Z');
    expect(Media::query()->whereKey($orphan->id)->exists())->toBeTrue();

    pruneExpiredData('2026-11-01T12:00:01Z');
    expect(Media::query()->whereKey($orphan->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists('waypost/media/orphan.jpg'))->toBeFalse()
        ->and(Media::query()->whereKey($ofDeleted->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists('waypost/media/deleted.jpg'))->toBeTrue();
});

it('keeps 62 days for a site with a monthly report', function (): void {
    $fixture = patrolledSite(['weekdays' => 127, 'start_times' => ['20:00'], 'time_limit_min' => 30]);
    $phone = $fixture['phone'] ?? throw new LogicException('The site has its phone.');
    ReportSubscription::factory()->create(['user_id' => supervisorOf($fixture['site'])->id, 'site_id' => $fixture['site']->id, 'frequency' => ReportFrequency::Monthly]);
    $chain = new ChainBuilder($phone);
    pushedPatrol($fixture, $chain, '2026-11-10T19:00:00Z');
    pushedPatrol($fixture, $chain, '2026-12-20T19:00:00Z');

    // 62 days before 2027-01-10: 2026-11-09 12:00, both stay.
    pruneExpiredData('2027-01-10T12:00:00Z');
    expect(rawData($phone)['shifts'])->toBe(2);

    // 62 days before 2027-01-12: 2026-11-11 12:00, the first goes.
    pruneExpiredData('2027-01-12T12:00:00Z');
    expect(rawData($phone))->toMatchArray(['chain_entries' => 8, 'shifts' => 1, 'scans' => 3, 'media' => 1]);
});

it('keeps a scan another shift\'s incident still cites, and the entries of a shift that is kept', function (): void {
    $fixture = patrolledSite(['weekdays' => 127, 'start_times' => ['20:00'], 'time_limit_min' => 30]);
    $phone = $fixture['phone'] ?? throw new LogicException('The site has its phone.');
    $chain = new ChainBuilder($phone);
    $old = pushedPatrol($fixture, $chain, '2026-11-10T19:00:00Z');
    // A kept shift's incident citing the old shift's scan outside the round.
    $recent = pushedPatrol($fixture, $chain, '2026-11-20T19:00:00Z');
    $looseScan = Scan::query()->where('chain_entry_id', $old['entries'][6]['id'])->sole();
    Incident::query()->where('shift_id', $recent['shift_id'])->sole()->update(['scan_id' => $looseScan->id]);

    pruneExpiredData('2026-12-12T12:00:00Z');

    expect(Scan::query()->whereKey($looseScan->id)->exists())->toBeTrue()
        ->and(ChainEntry::query()->whereKey($looseScan->chain_entry_id)->exists())->toBeTrue()
        ->and(Shift::query()->whereKey($old['shift_id'])->exists())->toBeFalse();
});
