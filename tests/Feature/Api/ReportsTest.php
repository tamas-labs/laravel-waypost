<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Waypost\Enums\MediaOwnerType;
use Waypost\Enums\ReportFrequency;
use Waypost\Http\SignedUrls;
use Waypost\Models\Media;
use Waypost\Models\Report;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Scan;
use Waypost\Models\Site;
use Waypost\Reports\Retention;
use Waypost\Support\Time;

use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    travelTo(CarbonImmutable::parse('2026-11-03T09:00:00Z'));
});

/** A weekly report of the site with its PDF on the disk. */
function storedReport(Site $site, string $periodStart = '2026-10-25T23:00:00.000Z'): Report
{
    $report = Report::factory()->for($site)->create([
        'type' => ReportFrequency::Weekly,
        'period_start' => Time::fromIso($periodStart),
        'period_end' => Time::fromIso($periodStart)->addDays(7),
        'sent_at' => Time::now(),
    ]);
    $path = "waypost/reports/{$report->id}.pdf";
    Storage::disk('local')->put($path, "%PDF-1.7 {$report->id}");
    $report->update(['path' => $path]);

    return $report;
}

/** An uploaded photo of a scan, taken at the time. */
function storedPhoto(string $takenAt): Media
{
    $media = Media::factory()->uploaded()->create(['owner_type' => MediaOwnerType::Scan->stored(), 'owner_id' => Scan::factory(), 'taken_at' => Time::fromIso($takenAt)]);
    Storage::disk('local')->put((string) $media->path, jpegBytes($media->id));

    return $media;
}

/** The expiry of a signed URL. */
function expiryOf(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    assert(is_numeric($query['expires'] ?? null));

    return Time::iso(CarbonImmutable::createFromTimestampUTC((int) $query['expires']));
}

it('lists the reports of the supervisor\'s sites, newest first, with a signed link to the stored file', function (): void {
    $site = Site::factory()->create();
    $other = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $weekly = storedReport($site);
    $daily = Report::factory()->for($site)->create(['period_start' => Time::fromIso('2026-11-01T23:00:00.000Z'), 'period_end' => Time::fromIso('2026-11-02T23:00:00.000Z')]);
    Report::factory()->for($other)->create();

    $response = callApi('GET', 'reports', $supervisor);

    $response->assertOk();
    expect($response)->toMatchSchema('api/report-list.response')
        ->and($response->json('reports.*.id'))->toBe([$daily->id, $weekly->id])
        ->and($response->json('next_cursor'))->toBeNull()
        ->and($response->json('reports.0.download'))->toBeNull()
        ->and($response->json('reports.0.sent_at'))->toBeNull()
        ->and($response->json('reports.1.period_start'))->toBe('2026-10-25T23:00:00.000Z')
        ->and($response->json('reports.1.download.expires_at'))->toBe('2026-11-03T10:00:00.000Z')
        ->and(expiryOf((string) $response->json('reports.1.download.url')))->toBe('2026-11-03T10:00:00.000Z');

    $file = get((string) $response->json('reports.1.download.url'));

    $file->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', "inline; filename={$weekly->id}.pdf");
    expect($file->streamedContent())->toBe("%PDF-1.7 {$weekly->id}");
});

it('filters by site and type, and shows nothing of another site', function (): void {
    $site = Site::factory()->create();
    $second = Site::factory()->create();
    $other = Site::factory()->create();
    $supervisor = supervisorOf($site, $second);
    $weekly = storedReport($site);
    $daily = Report::factory()->for($second)->create();
    Report::factory()->for($other)->create();

    expect(callApi('GET', "reports?site_id={$second->id}", $supervisor)->json('reports.*.id'))->toBe([$daily->id])
        ->and(callApi('GET', 'reports?type=weekly', $supervisor)->json('reports.*.id'))->toBe([$weekly->id])
        ->and(callApi('GET', "reports?site_id={$other->id}", $supervisor)->json('reports'))->toBe([]);

    $invalid = callApi('GET', 'reports?type=yearly', $supervisor);
    $invalid->assertStatus(422);
    expect($invalid)->toMatchSchema('api/error')
        ->and($invalid->json('error.details.type'))->toBe(['Ismeretlen jelentéstípus.']);
});

it('pages the archive with a cursor, also across reports of the same period', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    // 102 reports: 3 types of 34 periods, so every period start is shared.
    $start = CarbonImmutable::parse('2026-09-01T22:00:00Z');
    foreach (range(0, 33) as $day) {
        foreach ([ReportFrequency::Daily, ReportFrequency::Weekly, ReportFrequency::Monthly] as $type) {
            Report::factory()->for($site)->create(['type' => $type, 'period_start' => $start->addDays($day), 'period_end' => $start->addDays($day + 1)]);
        }
    }

    $first = callApi('GET', 'reports', $supervisor);
    $cursor = (string) $first->json('next_cursor');
    $second = callApi('GET', 'reports?cursor=' . rawurlencode($cursor), $supervisor);

    expect($first)->toMatchSchema('api/report-list.response')
        ->and($first->json('reports'))->toHaveCount(100)
        ->and($cursor)->not->toBe('')
        ->and($second->json('reports'))->toHaveCount(2)
        ->and($second->json('next_cursor'))->toBeNull()
        ->and(array_merge($first->json('reports.*.id'), $second->json('reports.*.id')))
        ->toBe(Report::query()->orderByDesc('period_start')->orderByDesc('id')->pluck('id')->all());

    $invalid = callApi('GET', 'reports?cursor=nonsense', $supervisor);
    $invalid->assertStatus(422);
    expect($invalid->json('error.details.cursor'))->toBe(['Hibás kurzor; kurzor nélkül az első oldal kérhető le.']);
});

it('refuses an expired or changed report link, and a file that is gone', function (): void {
    $site = Site::factory()->create();
    $report = storedReport($site);
    $another = storedReport(Site::factory()->create(), '2026-10-18T22:00:00.000Z');
    $download = SignedUrls::report($report);
    assert($download !== null);

    // Another site's report under the same signature.
    $changed = get(str_replace($report->id, $another->id, $download['url']));
    $changed->assertForbidden();
    expect($changed)->toMatchSchema('api/error')
        ->and($changed->json('error.message'))->toBe('A link lejárt vagy érvénytelen.');
    get($download['url'] . '&x=1')->assertForbidden();
    get(route(SignedUrls::REPORT_ROUTE, ['report' => $report->id]))->assertForbidden();

    // The data retention has deleted the file.
    Storage::disk('local')->delete((string) $report->path);
    get($download['url'])->assertNotFound();

    travelTo(Time::now()->addMinutes(61));
    get($download['url'])->assertForbidden();
});

it('signs a photo until the end of the data retention', function (): void {
    $site = Site::factory()->create();
    $photo = storedPhoto('2026-11-02T21:03:44.120Z');
    $url = SignedUrls::photo($photo, $site);
    assert($url !== null);

    expect(Retention::days($site))->toBe(31)
        ->and(expiryOf($url))->toBe('2026-12-03T21:03:44.000Z');
    $file = get($url);
    $file->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'inline; filename=' . basename((string) $photo->path));
    expect($file->streamedContent())->toBe(jpegBytes($photo->id));

    // Another photo under the same signature.
    $other = storedPhoto('2026-11-02T21:05:00.000Z');
    get(str_replace($photo->id, $other->id, $url))->assertForbidden();

    // With a monthly report the retention is 62 days.
    ReportSubscription::factory()->create(['site_id' => $site->id, 'frequency' => ReportFrequency::Monthly]);
    expect(Retention::days($site))->toBe(62)
        ->and(expiryOf((string) SignedUrls::photo($photo, $site)))->toBe('2027-01-03T21:03:44.000Z');

    travelTo(CarbonImmutable::parse('2026-12-04T09:00:00Z'));
    get($url)->assertForbidden();
});

it('signs no link for a photo not uploaded or already past the retention', function (): void {
    $site = Site::factory()->create();

    expect(SignedUrls::photo(Media::factory()->create(['taken_at' => Time::now()]), $site))->toBeNull()
        ->and(SignedUrls::photo(storedPhoto('2026-10-01T20:00:00.000Z'), $site))->toBeNull()
        ->and(SignedUrls::photo(storedPhoto('2026-10-03T20:00:00.000Z'), $site))->not->toBeNull();
});
