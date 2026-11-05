<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Waypost\Enums\ReportFrequency;
use Waypost\Mail\ReportMail;
use Waypost\Models\Report;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Reports\ReportSender;

use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    travelTo(CarbonImmutable::parse('2026-11-04T09:00:00Z'));
});

/** A site with three supervisors subscribed to its daily report. */
function siteWithThreeRecipients(): Site
{
    $site = Site::factory()->create();
    foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
        $user = User::factory()->supervisor()->create(['email' => $email]);
        $user->sites()->attach($site->id);
        ReportSubscription::factory()->create(['user_id' => $user->id, 'site_id' => $site->id, 'frequency' => ReportFrequency::Daily]);
    }

    return $site;
}

/**
 * Sends the site's daily report of 2026-11-03; the e-mail of the $failAt-th recipient (counted from 1) fails.
 */
function sendDaily(Site $site, ?int $failAt = null): ?Report
{
    $calls = 0;
    $mail = function (Report $report) use (&$calls, $failAt): ReportMail {
        if (++$calls === $failAt) {
            throw new RuntimeException('The mail server is down.');
        }

        return new ReportMail($report, 'waypost::mail.period-report', [], 'Napi jelentés');
    };

    return app(ReportSender::class)->send($site, ReportFrequency::Daily, CarbonImmutable::parse('2026-11-02T23:00:00Z'), CarbonImmutable::parse('2026-11-03T23:00:00Z'), $mail);
}

/** @return list<string> */
function mailedAddresses(): array
{
    return array_values(Mail::sent(ReportMail::class)->flatMap(fn (ReportMail $mail): array => array_column($mail->to, 'address'))->all());
}

it('sends a report again only to the recipients a failed attempt did not reach', function (): void {
    $site = siteWithThreeRecipients();

    expect(fn () => sendDaily($site, failAt: 2))->toThrow(RuntimeException::class, 'The mail server is down.');

    $claim = Report::query()->sole();
    expect(mailedAddresses())->toBe(['a@example.com'])
        ->and($claim->delivered_to)->toBe(['a@example.com'])
        ->and($claim->sent_at)->toBeNull()
        ->and(ReportSender::exists($site, ReportFrequency::Daily, CarbonImmutable::parse('2026-11-02T23:00:00Z')))->toBeFalse();

    $report = sendDaily($site);

    expect(mailedAddresses())->toBe(['a@example.com', 'b@example.com', 'c@example.com'])
        ->and($report?->sent_at)->not->toBeNull()
        ->and($report?->delivered_to)->toBe(['a@example.com', 'b@example.com', 'c@example.com'])
        ->and(Report::query()->count())->toBe(1);
});

it('undoes the claim of an attempt that reached nobody', function (): void {
    $site = siteWithThreeRecipients();

    expect(fn () => sendDaily($site, failAt: 1))->toThrow(RuntimeException::class);

    expect(Report::query()->count())->toBe(0);
    sendDaily($site);
    expect(mailedAddresses())->toBe(['a@example.com', 'b@example.com', 'c@example.com']);
});

it('sends only to the rest after an attempt that died halfway', function (): void {
    $site = siteWithThreeRecipients();
    // As if the first attempt had sent to a@ and b@, and its worker was killed then.
    Report::factory()->create(['site_id' => $site->id, 'type' => ReportFrequency::Daily, 'period_start' => CarbonImmutable::parse('2026-11-02T23:00:00Z'),
        'period_end' => CarbonImmutable::parse('2026-11-03T23:00:00Z'), 'generated_at' => CarbonImmutable::parse('2026-11-04T09:00:00Z'),
        'delivered_to' => ['a@example.com', 'b@example.com']]);

    travelTo(CarbonImmutable::parse('2026-11-04T09:14:59Z'));
    expect(sendDaily($site))->toBeNull();

    travelTo(CarbonImmutable::parse('2026-11-04T09:15:00Z'));
    $report = sendDaily($site);

    expect(mailedAddresses())->toBe(['c@example.com'])
        ->and($report?->delivered_to)->toBe(['a@example.com', 'b@example.com', 'c@example.com']);
});
