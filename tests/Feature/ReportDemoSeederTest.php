<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Waypost\Enums\VerifyStatus;
use Waypost\Models\ChainEntry;
use Waypost\Models\Media;
use Waypost\Models\Report;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Round;
use Waypost\Models\Shift;
use Waypost\Models\Site;
use Workbench\Database\Seeders\DatabaseSeeder;
use Workbench\Database\Seeders\ReportDemoSeeder;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

pest()->use(RefreshDatabase::class);

it('seeds a site with a pushed history and previews its reports', function (): void {
    Mail::fake();
    Storage::fake('local');
    seed(DatabaseSeeder::class);
    (new ReportDemoSeeder(nights: 10))->run();

    $site = Site::query()->where('name', ReportDemoSeeder::SITE_NAME)->sole();
    expect(Shift::query()->where('site_id', $site->id)->count())->toBeGreaterThanOrEqual(9)
        ->and(Round::query()->count())->toBeGreaterThanOrEqual(45)
        ->and(ChainEntry::query()->where('verify_status', '!=', VerifyStatus::Ok->value)->count())->toBe(0)
        ->and(ChainEntry::query()->whereNull('projected_at')->count())->toBe(0)
        ->and(Media::query()->whereNull('uploaded_at')->count())->toBe(0)
        // The demo supervisor and the site's own one, to every report.
        ->and(ReportSubscription::query()->where('site_id', $site->id)->count())->toBe(8)
        // The history's shift reports were not sent.
        ->and(Report::query()->count())->toBe(0);
    Mail::assertNothingSent();

    foreach (['shift', 'daily', 'weekly', 'monthly', 'no-data'] as $page) {
        expect(get("/reports/{$page}")->assertOk()->getContent())->toContain(ReportDemoSeeder::SITE_NAME);
    }
    foreach (['weekly.pdf', 'monthly.pdf'] as $page) {
        expect(get("/reports/{$page}")->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent())->toStartWith('%PDF-');
    }
});
