<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Waypost\Enums\ReportFrequency;
use Waypost\Jobs\SendShiftReport;
use Waypost\Mail\ReportMail;
use Waypost\Models\Device;
use Waypost\Models\Report;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Site;
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
 * A site patrolled at 20:00 and 22:00 (30 minutes each), with a supervisor subscribed to its shift reports.
 *
 * @return array{site: Site, points: list<\Waypost\Models\Checkpoint>, version: \Waypost\Models\RouteVersion, schedule: \Waypost\Models\Schedule, phone: Device, supervisor: User, chain: ChainBuilder}
 */
function shiftReportSite(): array
{
    $fixture = patrolledSite(['weekdays' => 127, 'start_times' => ['20:00', '22:00'], 'time_limit_min' => 30]);
    $phone = $fixture['phone'] ?? throw new LogicException('The site has its phone.');
    $supervisor = supervisorOf($fixture['site']);
    ReportSubscription::factory()->create(['user_id' => $supervisor->id, 'site_id' => $fixture['site']->id, 'frequency' => ReportFrequency::Shift]);

    return [...$fixture, 'phone' => $phone, 'supervisor' => $supervisor, 'chain' => new ChainBuilder($phone)];
}

/**
 * The entries of a shift on the fixture's phone: started at $from, the 20:00 round done (both points), an incident at
 * 20:30 (with a photo of $photo's bytes), closed at $to unless $closedBy is null. Times are UTC, of 2026-11-03.
 *
 * @param  array{points: list<\Waypost\Models\Checkpoint>, version: \Waypost\Models\RouteVersion, schedule: \Waypost\Models\Schedule, chain: ChainBuilder}  $fixture
 * @return array{shift_id: string, photo_id: string|null, incident_id: string, entries: list<array<string, mixed>>}
 */
function shiftEntries(array $fixture, User $guard, string $from, ?string $to, ?string $closedBy = 'guard', ?string $photo = null, bool $round = true): array
{
    $chain = $fixture['chain'];
    $at = fn (string $time): array => ['device_time' => "2026-11-03T{$time}.000Z"];
    $started = $chain->shiftStarted($guard, $at($from));
    $shiftId = $started['payload']['shift_id'];
    $entries = [$started];
    if ($round) {
        $roundId = Uuid::v7();
        $entries[] = $chain->entry('round_opened', ['round_id' => $roundId, 'shift_id' => $shiftId, 'route_version_id' => $fixture['version']->id,
            'schedule_id' => $fixture['schedule']->id, 'scheduled_at' => '2026-11-03T19:00:00.000Z', 'time_limit_min' => 30], $at('19:00:00'));
        $entries[] = $chain->scan($roundId, $fixture['points'][0]->id, [], $at('19:02:00'));
        $entries[] = $chain->scan($roundId, $fixture['points'][1]->id, [], $at('19:09:00'));
        $entries[] = $chain->entry('round_closed', ['round_id' => $roundId, 'status' => 'completed', 'finished_at' => '2026-11-03T19:09:00.000Z'], $at('19:09:00'));
    }
    $photoId = $photo === null ? null : Uuid::v7();
    $incident = $chain->incident($shiftId, ['media' => $photo === null ? [] : [['id' => $photoId, 'sha256' => hash('sha256', $photo)]]], $at('19:30:00'));
    $entries[] = $incident;
    if ($closedBy !== null && $to !== null) {
        $entries[] = $chain->shiftClosed($shiftId, $closedBy, $at($to));
    }

    return ['shift_id' => $shiftId, 'photo_id' => $photoId, 'incident_id' => $incident['payload']['incident_id'], 'entries' => $entries];
}

/** @return list<ReportMail> */
function sentReportMails(): array
{
    return array_values(Mail::sent(ReportMail::class)->all());
}

/** Runs the queued shift report jobs not run yet, as a worker would; returns how many ran. */
function runShiftReportJobs(int $from = 0): int
{
    $jobs = Queue::pushed(SendShiftReport::class)->values()->slice($from);
    foreach ($jobs as $job) {
        assert($job instanceof SendShiftReport);
        app()->call([$job, 'handle']);
    }

    return $jobs->count();
}

/** A queue that can delay jobs (the tests' sync queue cannot), faked. */
function delayingQueue(): void
{
    config(['queue.default' => 'database']);
    Queue::fake();
}

it('sends the shift report when the close is processed, to the subscribed supervisors of the site', function (): void {
    $fixture = shiftReportSite();
    $site = $fixture['site'];
    $guard = User::factory()->create(['name' => 'Nagy Péter']);
    // Not recipients: subscribed to another report, inactive, not assigned to the site, deleted subscription.
    $weekly = supervisorOf($site);
    ReportSubscription::factory()->create(['user_id' => $weekly->id, 'site_id' => $site->id, 'frequency' => ReportFrequency::Weekly]);
    $inactive = supervisorOf($site);
    $inactive->update(['active' => false]);
    $elsewhere = supervisorOf(Site::factory()->create());
    $deleted = supervisorOf($site);
    foreach ([$inactive, $elsewhere, $deleted] as $user) {
        ReportSubscription::factory()->create(['user_id' => $user->id, 'site_id' => $site->id, 'frequency' => ReportFrequency::Shift]);
    }
    ReportSubscription::query()->where('user_id', $deleted->id)->delete();

    $shift = shiftEntries($fixture, $guard, '18:55:00', '21:40:00');
    travelTo(CarbonImmutable::parse('2026-11-03T21:41:00Z'));
    pushAs($fixture['phone'], $shift['entries'])->assertOk();

    $mails = sentReportMails();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->hasTo((string) $fixture['supervisor']->email))->toBeTrue()
        ->and($mails[0]->type())->toBe(ReportFrequency::Shift)
        ->and($mails[0]->subjectLine)->toBe("Műszakjelentés – {$site->name} – 2026. november 3. 19:55–22:40");

    $report = Report::query()->sole();
    expect($report->type)->toBe(ReportFrequency::Shift)
        ->and(Time::iso($report->period_start))->toBe('2026-11-03T18:55:00.000Z')
        ->and(Time::iso($report->period_end))->toBe('2026-11-03T21:40:00.000Z')
        ->and($report->sent_at)->not->toBeNull()
        ->and($report->path)->toBeNull()
        ->and($report->recipients)->toBe([$fixture['supervisor']->email]);

    $content = $mails[0]->content;
    expect($content['shift'])->toMatchArray(['from' => '19:55', 'to' => '22:40', 'guard' => 'Nagy Péter', 'closed_by' => 'guard', 'late_sync_at' => null])
        // 20:00 done; 22:00 opened by nobody while the shift was open: missed.
        ->and(array_column($content['rounds'], 'status'))->toBe(['completed', 'missed'])
        ->and($content['rounds'][0])->toMatchArray(['time' => '20:00', 'start' => '20:02', 'duration_min' => 7, 'done' => 2, 'total' => 2])
        ->and($content['totals'])->toMatchArray(['completed' => 1, 'missed' => 1, 'incidents' => 1])
        ->and($content['incidents'][0])->toMatchArray(['time' => '20:30', 'category' => 'Nyitott ajtó / ablak', 'photos' => []])
        ->and($content['incomplete'])->toBe([])
        ->and($content['chain']['ok'])->toBeTrue();

    $html = $mails[0]->render();
    expect($html)->toContain('Waypost', 'Műszakjelentés', e($site->name), 'Őr: Nagy Péter', 'Teljesítve', 'Elmaradt', 'Nyitott ajtó / ablak', 'Rendben.')
        ->and(str_contains($html, 'Őrjárat'))->toBeFalse();

    // The same close again (a repeated push) sends nothing.
    pushAs($fixture['phone'], $shift['entries'])->assertOk();
    app()->call([new SendShiftReport($shift['shift_id'], Time::iso(Time::now())), 'handle']);
    expect(sentReportMails())->toHaveCount(1);
});

it('waits for a missing photo and sends the report as soon as it has arrived', function (): void {
    delayingQueue();
    $fixture = shiftReportSite();
    $bytes = jpegBytes('on-time');
    $shift = shiftEntries($fixture, User::factory()->create(), '18:55:00', '21:40:00', photo: $bytes);
    travelTo(CarbonImmutable::parse('2026-11-03T21:41:00Z'));
    pushAs($fixture['phone'], $shift['entries'])->assertOk();

    Queue::assertPushed(SendShiftReport::class, 1);
    expect(Queue::pushed(SendShiftReport::class)->first()?->photosDueAt)->toBe('2026-11-03T22:11:00.000Z');
    runShiftReportJobs();

    // The photo is missing: checked again a minute later.
    expect(sentReportMails())->toBe([]);
    Queue::assertPushed(SendShiftReport::class, fn (SendShiftReport $job): bool => $job->delay === SendShiftReport::RECHECK_S);

    travelTo(CarbonImmutable::parse('2026-11-03T21:42:00Z'));
    uploadMedia((string) $shift['photo_id'], ['sha256' => hash('sha256', $bytes), 'owner_type' => 'incident', 'owner_id' => $shift['incident_id'],
        'taken_at' => '2026-11-03T19:30:00.000Z'], ['Authorization' => 'Bearer ' . deviceToken($fixture['phone'])], $bytes)->assertOk();
    runShiftReportJobs(1);

    $mails = sentReportMails();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->content['incidents'][0]['photos'])->toHaveCount(1)
        ->and($mails[0]->content['incidents'][0]['photos'][0]['url'])->toContain("/media/{$shift['photo_id']}/file", 'signature=')
        ->and($mails[0]->content['incidents'][0]['photos_missing'])->toBe(0)
        ->and($mails[0]->content['incomplete'])->toBe([]);
});

it('sends the report without the photo after 30 minutes, and not again when it arrives', function (): void {
    delayingQueue();
    $fixture = shiftReportSite();
    $bytes = jpegBytes('late');
    $shift = shiftEntries($fixture, User::factory()->create(), '18:55:00', '21:40:00', photo: $bytes);
    travelTo(CarbonImmutable::parse('2026-11-03T21:41:00Z'));
    pushAs($fixture['phone'], $shift['entries'])->assertOk();

    travelTo(CarbonImmutable::parse('2026-11-03T22:11:00Z'));
    runShiftReportJobs();

    $mails = sentReportMails();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->subjectLine)->toEndWith('(nem teljes adat)')
        ->and($mails[0]->content['incidents'][0]['photos'])->toBe([])
        ->and($mails[0]->content['incidents'][0]['photos_missing'])->toBe(1)
        ->and($mails[0]->content['incomplete'])->toBe(['néhány fotó még nem töltődött fel'])
        ->and($mails[0]->render())->toContain('Nem teljes adat:', '1 fotó még nem érkezett meg.');

    uploadMedia((string) $shift['photo_id'], ['sha256' => hash('sha256', $bytes), 'owner_type' => 'incident', 'owner_id' => $shift['incident_id'],
        'taken_at' => '2026-11-03T19:30:00.000Z'], ['Authorization' => 'Bearer ' . deviceToken($fixture['phone'])], $bytes)->assertOk();
    pushAs($fixture['phone'], $shift['entries'])->assertOk();
    runShiftReportJobs(1);

    expect(sentReportMails())->toHaveCount(1)
        ->and(Report::query()->count())->toBe(1);
});

it('tells a handover with the guard who took over, and an auto close with the round nobody opened', function (): void {
    $fixture = shiftReportSite();
    $first = User::factory()->create(['name' => 'Nagy Péter']);
    $second = User::factory()->create(['name' => 'Kiss Gábor']);
    $evening = shiftEntries($fixture, $first, '18:55:00', '19:40:00', 'handover');
    // Kiss Gábor's shift has no round: the 22:00 one is missed; the phone closes it at 23:40 by itself.
    $night = shiftEntries($fixture, $second, '19:40:00', '22:40:00', 'auto', round: false);
    travelTo(CarbonImmutable::parse('2026-11-03T22:41:00Z'));
    pushAs($fixture['phone'], [...$evening['entries'], ...$night['entries']])->assertOk();

    $mails = sentReportMails();
    expect($mails)->toHaveCount(2);
    [$handedOver, $auto] = $mails;

    expect($handedOver->content['shift'])->toMatchArray(['guard' => 'Nagy Péter', 'closed_by' => 'handover', 'handover_guard' => 'Kiss Gábor'])
        ->and(array_column($handedOver->content['rounds'], 'time'))->toBe(['20:00'])
        ->and($handedOver->render())->toContain('A műszakot Kiss Gábor PIN-belépése zárta (átadás).')
        ->and($auto->content['shift'])->toMatchArray(['guard' => 'Kiss Gábor', 'closed_by' => 'auto', 'handover_guard' => null])
        ->and(array_map(fn (array $round): array => [$round['time'], $round['status'], $round['done'], $round['total']], $auto->content['rounds']))
        ->toBe([['22:00', 'missed', 0, 2]])
        ->and($auto->render())->toContain('automatikusan zárult', 'Mind a 2 pont hiányzik.');
});

it('tells when the shift arrived late', function (): void {
    $fixture = shiftReportSite();
    $shift = shiftEntries($fixture, User::factory()->create(), '18:55:00', '21:40:00');
    // More than 12 hours after the close: 10:45 local the next morning.
    travelTo(CarbonImmutable::parse('2026-11-04T09:45:00Z'));
    pushAs($fixture['phone'], $shift['entries'])->assertOk();

    $mail = sentReportMails()[0];
    expect($mail->content['shift']['late_sync_at'])->toBe('november 4. 10:45')
        ->and($mail->render())->toContain('Később érkezett:', 'november 4. 10:45-kor szinkronizált');
});

it('reports the shift a replaced phone left open when its last upload is closed', function (): void {
    $fixture = shiftReportSite();
    $phone = $fixture['phone'];
    $shift = shiftEntries($fixture, User::factory()->create(['name' => 'Nagy Péter']), '18:55:00', null, null);
    travelTo(CarbonImmutable::parse('2026-11-03T19:45:00Z'));
    pushAs($phone, $shift['entries'])->assertOk();
    expect(sentReportMails())->toBe([]);

    // Replaced at 20:40 local; its last upload closes the next morning.
    $phone->update(['revoked_at' => Time::now()->subMinutes(5)]);
    travelTo(CarbonImmutable::parse('2026-11-04T06:00:00Z'));
    pushAs($phone, [], done: true)->assertOk();

    $mails = sentReportMails();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->content['shift'])->toMatchArray(['from' => '19:55', 'to' => '20:30', 'closed_by' => null])
        ->and(array_column($mails[0]->content['rounds'], 'status'))->toBe(['completed'])
        ->and($mails[0]->render())->toContain('A műszak nem zárult le: a telefont lecserélték');
    expect(Time::iso(Report::query()->sole()->period_end))->toBe('2026-11-03T19:30:00.000Z');
});

it('sends no shift report without a subscriber, nor for a shift still open', function (): void {
    $fixture = shiftReportSite();
    ReportSubscription::query()->delete();
    $closed = shiftEntries($fixture, User::factory()->create(), '18:55:00', '21:40:00');
    travelTo(CarbonImmutable::parse('2026-11-03T21:41:00Z'));
    pushAs($fixture['phone'], $closed['entries'])->assertOk();

    expect(sentReportMails())->toBe([])
        ->and(Report::query()->count())->toBe(0);

    ReportSubscription::factory()->create(['user_id' => $fixture['supervisor']->id, 'site_id' => $fixture['site']->id, 'frequency' => ReportFrequency::Shift]);
    $open = shiftEntries($fixture, User::factory()->create(), '21:45:00', null, null, round: false);
    pushAs($fixture['phone'], $open['entries'])->assertOk();
    app()->call([new SendShiftReport($open['shift_id'], Time::iso(Time::now())), 'handle']);

    expect(sentReportMails())->toBe([]);
});

it('sends the report again once the claim of an attempt that died is abandoned', function (): void {
    delayingQueue();
    $fixture = shiftReportSite();
    $shift = shiftEntries($fixture, User::factory()->create(), '18:55:00', '21:40:00');
    travelTo(CarbonImmutable::parse('2026-11-03T21:41:00Z'));
    pushAs($fixture['phone'], $shift['entries'])->assertOk();
    // As if the first attempt had claimed the report, and its worker was killed while sending.
    $claim = Report::factory()->create(['site_id' => $fixture['site']->id, 'type' => ReportFrequency::Shift,
        'period_start' => Time::fromIso('2026-11-03T18:55:00.000Z'), 'period_end' => Time::fromIso('2026-11-03T21:40:00.000Z')]);

    // The queue's retry waits until the claim is abandoned.
    travelTo(CarbonImmutable::parse('2026-11-03T21:42:30Z'));
    $retry = (new SendShiftReport($shift['shift_id'], Time::iso(Time::now())))->withFakeQueueInteractions();
    app()->call([$retry, 'handle']);
    $retry->assertReleased(15 * 60 - 90 + 1);
    expect(sentReportMails())->toBe([]);

    travelTo(CarbonImmutable::parse('2026-11-03T21:56:00Z'));
    app()->call([new SendShiftReport($shift['shift_id'], Time::iso(Time::now())), 'handle']);

    expect(sentReportMails())->toHaveCount(1);
    $report = Report::query()->sole();
    expect($report->id)->not->toBe($claim->id)
        ->and($report->sent_at)->not->toBeNull();
});
