<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule as Scheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Waypost\Enums\IncidentCategory;
use Waypost\Enums\ReportFrequency;
use Waypost\Enums\RoundStatus;
use Waypost\Jobs\SendDueReports;
use Waypost\Jobs\SendPeriodReport;
use Waypost\Mail\ReportMail;
use Waypost\Models\Device;
use Waypost\Models\Incident;
use Waypost\Models\Media;
use Waypost\Models\Report;
use Waypost\Models\ReportSubscription;
use Waypost\Models\User;
use Illuminate\Support\Str;
use Waypost\Support\Time;

use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    Storage::fake('local');
});

/**
 * A site patrolled at 20:00 and 22:00 (30 minutes each) in the time zone, with a supervisor subscribed to the report;
 * its phone synced last at $seen.
 *
 * @return array{site: \Waypost\Models\Site, points: list<\Waypost\Models\Checkpoint>, version: \Waypost\Models\RouteVersion, schedule: \Waypost\Models\Schedule, phone: Device, supervisor: User}
 */
function periodReportSite(ReportFrequency $type, string $timezone = 'Europe/Budapest', string $seen = '2026-12-31T00:00:00Z'): array
{
    $fixture = patrolledSite(['weekdays' => 127, 'start_times' => ['20:00', '22:00'], 'time_limit_min' => 30]);
    $fixture['site']->update(['timezone' => $timezone]);
    $phone = $fixture['phone'] ?? throw new LogicException('The site has its phone.');
    $phone->update(['last_seen_at' => CarbonImmutable::parse($seen)]);
    $supervisor = supervisorOf($fixture['site']);
    ReportSubscription::factory()->create(['user_id' => $supervisor->id, 'site_id' => $fixture['site']->id, 'frequency' => $type]);

    return [...$fixture, 'phone' => $phone, 'supervisor' => $supervisor];
}

function sendDueReports(string $now): void
{
    travelTo(CarbonImmutable::parse($now));
    app()->call([new SendDueReports(), 'handle']);
}

/** @return list<ReportMail> */
function sentPeriodMails(): array
{
    return array_values(Mail::sent(ReportMail::class)->all());
}

it('sends the daily report at 10:00 on the clock of each site, once', function (): void {
    $budapest = periodReportSite(ReportFrequency::Daily);
    $newYork = periodReportSite(ReportFrequency::Daily, 'America/New_York');

    sendDueReports('2026-11-04T08:59:00Z');
    expect(sentPeriodMails())->toBe([]);

    // 10:00 in Budapest (CET), 04:00 in New York.
    sendDueReports('2026-11-04T09:00:00Z');
    sendDueReports('2026-11-04T09:15:00Z');
    $mails = sentPeriodMails();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->hasTo((string) $budapest['supervisor']->email))->toBeTrue()
        ->and($mails[0]->subjectLine)->toBe("Napi jelentés – {$budapest['site']->name} – 2026. november 3., kedd");

    // 10:00 in New York (EST).
    sendDueReports('2026-11-04T14:59:00Z');
    expect(sentPeriodMails())->toHaveCount(1);
    sendDueReports('2026-11-04T15:00:00Z');
    sendDueReports('2026-11-04T15:15:00Z');
    $mails = sentPeriodMails();
    expect($mails)->toHaveCount(2)
        ->and($mails[1]->hasTo((string) $newYork['supervisor']->email))->toBeTrue();

    $reports = Report::query()->orderBy('period_start')->get();
    expect($reports->map(fn (Report $report): array => [$report->site_id, $report->type, Time::iso($report->period_start), Time::iso($report->period_end), $report->path])->all())
        ->toBe([
            [$budapest['site']->id, ReportFrequency::Daily, '2026-11-02T23:00:00.000Z', '2026-11-03T23:00:00.000Z', null],
            [$newYork['site']->id, ReportFrequency::Daily, '2026-11-03T05:00:00.000Z', '2026-11-04T05:00:00.000Z', null],
        ])
        ->and($reports->every(fn (Report $report): bool => $report->sent_at !== null))->toBeTrue();
});

it('lists the counts, the bad rounds, the flags and the incidents of the day', function (): void {
    $fixture = periodReportSite(ReportFrequency::Daily);
    $phone = $fixture['phone'];
    [$first, $second] = $fixture['points'];
    $first->update(['name' => 'Főbejárat']);
    $second->update(['name' => 'Raktár 2']);
    $shift = shiftOf($phone, '2026-11-03T18:55:00.000Z', '2026-11-03T21:40:00.000Z');
    $shift->guardUser()->update(['name' => 'Nagy Péter']);
    roundOf($shift, $fixture, '2026-11-03T19:00:00.000Z', RoundStatus::Completed, [0 => '2026-11-03T19:02:00.000Z', 1 => '2026-11-03T19:09:00.000Z']);
    $late = roundOf($shift, $fixture, '2026-11-03T21:00:00.000Z', RoundStatus::Incomplete);
    scanOf($phone, $late, $second, '2026-11-03T21:05:00.000Z', ['manual']);
    Incident::factory()->create(['shift_id' => $shift->id, 'category' => IncidentCategory::DamagedTag, 'occurred_at' => Time::fromIso('2026-11-03T21:05:00.000Z'), 'note' => 'Letört a címke.']);

    travelTo(CarbonImmutable::parse('2026-11-04T09:00:00Z'));
    app()->call([new SendPeriodReport($fixture['site']->id, ReportFrequency::Daily, '2026-11-03', '2026-11-03'), 'handle']);

    $mail = sentPeriodMails()[0];
    $content = $mail->content;
    expect($content['totals'])->toMatchArray(['expected' => 2, 'completed' => 1, 'incomplete' => 1, 'missed' => 0, 'incidents' => 1, 'manual' => 1, 'rate' => '50%'])
        ->and($content['days'])->toBe([])
        ->and($content['problem_rounds'])->toHaveCount(1)
        ->and($content['problem_rounds'][0])->toMatchArray(['time' => '22:00', 'status' => 'incomplete', 'done' => 1, 'total' => 2, 'missing' => 'Főbejárat'])
        ->and($content['flags'])->toBe([['label' => 'nem igazolt', 'count' => 1]])
        ->and($content['flagged_scans'][0])->toMatchArray(['name' => 'Raktár 2', 'time' => '22:05', 'method' => 'NFC', 'flag_text' => 'nem igazolt'])
        ->and($content['incidents'][0])->toMatchArray(['time' => '22:05', 'category' => 'Sérült címke', 'note' => 'Letört a címke.'])
        ->and($content['shifts'][0])->toMatchArray(['from' => '19:55', 'to' => '22:40', 'guard' => 'Nagy Péter', 'closed_by_text' => 'az őr zárta'])
        ->and($content['incomplete'])->toBe([])
        ->and($mail->attachments())->toBe([])
        ->and($mail->render())->toContain('Napi jelentés', '2026. november 3., kedd', 'Elmaradt és hiányos körök', 'Főbejárat', 'Sérült címke', '50%');
});

it('sends the weekly report on Monday with its PDF, the photos embedded, and stores the PDF', function (): void {
    $fixture = periodReportSite(ReportFrequency::Weekly);
    $phone = $fixture['phone'];
    $shift = shiftOf($phone, '2026-11-03T18:55:00.000Z', '2026-11-03T21:40:00.000Z');
    roundOf($shift, $fixture, '2026-11-03T19:00:00.000Z', RoundStatus::Completed, [0 => '2026-11-03T19:02:00.000Z', 1 => '2026-11-03T19:09:00.000Z']);
    $incident = Incident::factory()->create(['shift_id' => $shift->id, 'category' => IncidentCategory::OpenDoor, 'occurred_at' => Time::fromIso('2026-11-03T19:30:00.000Z')]);
    $bytes = realJpegBytes(1600, 1200);
    Storage::disk('local')->put('waypost/media/photo.jpg', $bytes);
    Media::factory()->create(['owner_type' => Incident::class, 'owner_id' => $incident->id, 'device_id' => $phone->id, 'sha256' => hash('sha256', $bytes),
        'mime' => 'image/jpeg', 'size_bytes' => strlen($bytes), 'path' => 'waypost/media/photo.jpg', 'taken_at' => Time::fromIso('2026-11-03T19:30:00.000Z'),
        'uploaded_at' => Time::fromIso('2026-11-03T19:31:00.000Z')]);

    sendDueReports('2026-11-09T08:59:00Z');
    expect(sentPeriodMails())->toBe([]);
    sendDueReports('2026-11-09T09:00:00Z');

    $mail = sentPeriodMails()[0];
    $report = Report::query()->sole();
    expect($mail->subjectLine)->toBe("Heti jelentés – {$fixture['site']->name} – 2026. november 2–8.")
        ->and($mail->content['days'])->toHaveCount(7)
        ->and($mail->content['days'][1])->toMatchArray(['label' => 'november 3.', 'weekday' => 'K', 'expected' => 2, 'completed' => 1, 'missed' => 1, 'rate' => '50%'])
        // 7 days, 2 rounds a day, one of them done.
        ->and($mail->content['totals'])->toMatchArray(['expected' => 14, 'completed' => 1, 'missed' => 13, 'rate' => '7%'])
        ->and($mail->content['incident_categories'])->toBe([['label' => 'Nyitott ajtó / ablak', 'count' => 1]])
        ->and($mail->attachments())->toHaveCount(1)
        ->and($mail->pdfName)->toBe('waypost-heti-jelentes-' . Str::slug($fixture['site']->name) . '-2026-11-02.pdf')
        ->and(Time::iso($report->period_start))->toBe('2026-11-01T23:00:00.000Z')
        ->and(Time::iso($report->period_end))->toBe('2026-11-08T23:00:00.000Z')
        ->and($report->path)->toBe("waypost/reports/{$report->id}.pdf");

    $pdf = (string) $mail->pdf;
    expect($pdf)->toStartWith('%PDF-')
        ->and(Storage::disk('local')->get((string) $report->path))->toBe($pdf)
        // The photo as an image of the PDF, scaled down; the text in DejaVu Sans.
        ->and($pdf)->toContain('/Subtype /Image', '/Width 480', 'DejaVuSans')
        ->and(strlen($pdf))->toBeLessThan(strlen($bytes) + 200_000);

    // The archive lists it with its link.
    $response = callApi('GET', 'reports', $fixture['supervisor'])->assertOk();
    expect($response->json('reports.0.download.url'))->toContain("/reports/{$report->id}/file");
});

it('sends the monthly report on the 1st for the calendar month before, with its PDF', function (): void {
    $fixture = periodReportSite(ReportFrequency::Monthly);
    // The site existed from 2026-10-01 12:00 UTC: the first night of October is the 1st.
    sendDueReports('2026-11-01T09:00:00Z');

    $mail = sentPeriodMails()[0];
    $report = Report::query()->sole();
    expect($mail->subjectLine)->toBe("Havi jelentés – {$fixture['site']->name} – 2026. október")
        ->and($mail->content['days'])->toHaveCount(31)
        ->and($mail->content['totals']['expected'])->toBe(62)
        ->and((string) $mail->pdf)->toStartWith('%PDF-')
        ->and(Time::iso($report->period_start))->toBe('2026-09-30T22:00:00.000Z')
        ->and(Time::iso($report->period_end))->toBe('2026-10-31T23:00:00.000Z');
});

it('tells at the top when the data is not complete yet', function (): void {
    $fixture = periodReportSite(ReportFrequency::Daily, seen: '2026-11-03T18:00:00Z');

    sendDueReports('2026-11-04T09:00:00Z');

    $mail = sentPeriodMails()[0];
    expect($mail->subjectLine)->toEndWith('(nem teljes adat)')
        ->and($mail->content['incomplete'])->toBe(['a telefon az időszak vége óta nem szinkronizált'])
        ->and($mail->render())->toContain('Nem teljes adat:');
});

it('schedules the due reports every 15 minutes on the configured queue', function (): void {
    $events = array_filter(app(Scheduler::class)->events(), fn ($event): bool => $event instanceof CallbackEvent && $event->description === 'waypost:send-due-reports');
    expect($events)->toHaveCount(1)
        ->and(array_values($events)[0]->expression)->toBe('*/15 * * * *');

    config(['waypost.queue.connection' => 'redis', 'waypost.queue.queue' => 'waypost']);
    $job = new SendPeriodReport('x', ReportFrequency::Daily, '2026-11-03', '2026-11-03');
    expect([$job->connection, $job->queue])->toBe(['redis', 'waypost']);
});
