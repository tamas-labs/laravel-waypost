<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule as Scheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Waypost\Enums\RoundStatus;
use Waypost\Jobs\SendNoDataAlerts;
use Waypost\Mail\NoDataAlertMail;
use Waypost\Models\Device;
use Waypost\Models\NoDataAlert;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Time;

use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
});

/**
 * A site patrolled at 20:00 and 22:00 (30 minutes each, CET), alerting after 120 minutes, with two supervisors and a
 * guard; its phone, enrolled on 2026-10-01, last synced at $seen.
 *
 * @return array{site: Site, points: list<\Waypost\Models\Checkpoint>, version: \Waypost\Models\RouteVersion, schedule: \Waypost\Models\Schedule, route: \Waypost\Models\Route, phone: Device, supervisors: list<User>}
 */
function alertedSite(?string $seen): array
{
    $fixture = patrolledSite(['weekdays' => 127, 'start_times' => ['20:00', '22:00'], 'time_limit_min' => 30]);
    $fixture['route']->update(['name' => 'Külső kör']);
    $phone = $fixture['phone'] ?? throw new LogicException('The site has its phone.');
    $phone->update(['last_seen_at' => $seen === null ? null : CarbonImmutable::parse($seen)]);
    $fixture['site']->users()->attach(User::factory()->create());

    return [...$fixture, 'phone' => $phone, 'supervisors' => [supervisorOf($fixture['site']), supervisorOf($fixture['site'])]];
}

function sendNoDataAlerts(string $now): void
{
    travelTo(CarbonImmutable::parse($now));
    app()->call([new SendNoDataAlerts(), 'handle']);
}

/** @return list<NoDataAlertMail> */
function sentAlerts(): array
{
    return array_values(Mail::sent(NoDataAlertMail::class)->all());
}

it('alerts every supervisor when the phone has been silent for the given time after the last round was due', function (): void {
    $fixture = alertedSite('2026-11-03T18:00:00Z');

    // 20:00 local is due at 20:30 (19:30 UTC); 120 minutes later is 22:30.
    sendNoDataAlerts('2026-11-03T21:29:00Z');
    expect(sentAlerts())->toBe([]);
    sendNoDataAlerts('2026-11-03T21:30:00Z');

    $mails = sentAlerts();
    $emails = array_map(fn (User $user): string => (string) $user->email, $fixture['supervisors']);
    sort($emails);
    expect($mails)->toHaveCount(2)
        ->and($mails[0]->hasTo($emails[0]))->toBeTrue()
        ->and($mails[1]->hasTo($emails[1]))->toBeTrue()
        ->and($mails[0]->envelope()->subject)->toBe("Nincs adat – {$fixture['site']->name}")
        ->and($mails[0]->content)->toMatchArray([
            'last_seen' => '2026. november 3. 19:00',
            'round_label' => '2026. november 3. 20:00 (Külső kör)',
            'deadline' => '20:30',
            'elapsed' => '2 óra',
            'after' => '2 óra',
        ])
        ->and($mails[0]->render())->toContain('Nincs adat.', 'A telephelyi telefon 2026. november 3. 19:00 óta nem jelentkezett.', '(2 óra)');

    $alert = NoDataAlert::query()->sole();
    expect($alert->window_day)->toBe('2026-11-03')
        ->and(Time::iso($alert->scheduled_at))->toBe('2026-11-03T19:00:00.000Z')
        ->and(Time::iso($alert->deadline))->toBe('2026-11-03T19:30:00.000Z')
        ->and(Time::iso((CarbonImmutable::instance($alert->last_seen_at ?? throw new LogicException()))))->toBe('2026-11-03T18:00:00.000Z')
        ->and($alert->recipients)->toBe($emails)
        ->and($alert->sent_at)->not->toBeNull();
});

it('alerts once a schedule window, and again in the next one', function (): void {
    alertedSite('2026-11-03T18:00:00Z');

    sendNoDataAlerts('2026-11-03T21:30:00Z');
    sendNoDataAlerts('2026-11-03T21:45:00Z');
    // 22:00 is due too by now, in the same window.
    sendNoDataAlerts('2026-11-03T23:30:00Z');
    sendNoDataAlerts('2026-11-04T08:00:00Z');
    expect(sentAlerts())->toHaveCount(2);

    // The next evening's window.
    sendNoDataAlerts('2026-11-04T21:30:00Z');
    expect(sentAlerts())->toHaveCount(4)
        ->and(NoDataAlert::query()->orderBy('window_day')->pluck('window_day')->all())->toBe(['2026-11-03', '2026-11-04']);
});

it('says nothing while the phone syncs or the round has arrived', function (): void {
    $fixture = alertedSite('2026-11-03T19:31:00Z');

    // Synced after the deadline.
    sendNoDataAlerts('2026-11-03T21:30:00Z');
    expect(sentAlerts())->toBe([]);

    // Silent since before the deadline, but the round is on the server.
    $fixture['phone']->update(['last_seen_at' => CarbonImmutable::parse('2026-11-04T18:00:00Z')]);
    $shift = shiftOf($fixture['phone'], '2026-11-04T18:55:00.000Z');
    roundOf($shift, $fixture, '2026-11-04T19:00:00.000Z', RoundStatus::InProgress, [0 => '2026-11-04T19:02:00.000Z']);
    sendNoDataAlerts('2026-11-04T21:30:00Z');
    expect(sentAlerts())->toBe([])
        ->and(NoDataAlert::query()->count())->toBe(0);
});

it('says nothing about a site without a working phone, and tells a phone never heard of', function (): void {
    $fixture = alertedSite(null);
    $phone = $fixture['phone'];

    // Revoked: there is no phone to wait for.
    $phone->update(['revoked_at' => CarbonImmutable::parse('2026-11-01T12:00:00Z')]);
    sendNoDataAlerts('2026-11-03T21:30:00Z');
    expect(sentAlerts())->toBe([]);

    // Enrolled after the round was due: nothing was to come from it yet.
    $phone->update(['revoked_at' => null, 'enrolled_at' => CarbonImmutable::parse('2026-11-03T20:00:00Z')]);
    sendNoDataAlerts('2026-11-03T21:30:00Z');
    expect(sentAlerts())->toBe([]);

    // Enrolled before, and not heard of since.
    $phone->update(['enrolled_at' => CarbonImmutable::parse('2026-11-03T12:00:00Z')]);
    sendNoDataAlerts('2026-11-03T21:45:00Z');
    expect(sentAlerts())->toHaveCount(2)
        ->and(sentAlerts()[0]->content['last_seen'])->toBeNull()
        ->and(sentAlerts()[0]->render())->toContain('a hozzárendelése óta nem jelentkezett');
});

it('follows the site\'s setting', function (): void {
    $fixture = alertedSite('2026-11-03T18:00:00Z');
    $fixture['site']->update(['no_data_alert_after_min' => 30]);

    sendNoDataAlerts('2026-11-03T19:59:00Z');
    expect(sentAlerts())->toBe([]);
    sendNoDataAlerts('2026-11-03T20:00:00Z');
    expect(sentAlerts())->toHaveCount(2)
        ->and(sentAlerts()[0]->content['after'])->toBe('30 perc');
});

it('schedules the alerts every 15 minutes and the retention daily', function (): void {
    $expressions = [];
    foreach (app(Scheduler::class)->events() as $event) {
        if ($event instanceof CallbackEvent && in_array($event->description, ['waypost:send-no-data-alerts', 'waypost:prune-expired-data'], true)) {
            $expressions[$event->description] = $event->expression;
        }
    }

    expect($expressions)->toBe(['waypost:send-no-data-alerts' => '*/15 * * * *', 'waypost:prune-expired-data' => '30 3 * * *']);
});
