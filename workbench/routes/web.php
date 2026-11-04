<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Waypost\Enums\ReportFrequency;
use Waypost\Jobs\SendNoDataAlerts;
use Waypost\Jobs\SendPeriodReport;
use Waypost\Models\Device;
use Waypost\Models\NoDataAlert;
use Waypost\Models\Shift;
use Waypost\Models\Site;
use Waypost\Reports\ReportContent;
use Waypost\Reports\ReportPeriod;
use Waypost\Reports\RoundRow;
use Waypost\Reports\Summarizer;
use Waypost\Support\Time;
use Workbench\Database\Seeders\ReportDemoSeeder;

Route::get('/', fn () => response()->json([
    'name' => 'Waypost workbench',
    'api_prefix' => config('waypost.route.prefix'),
    'database' => DB::connection()->getDriverName() . ' ' . DB::connection()->getDatabaseName(),
]));

/*
| Report previews (M6-12): the e-mails and PDFs as they would go out now, from the data of a site (?site=<id>, by
| default the ReportDemoSeeder's). Nothing is sent or stored.
*/
Route::prefix('reports')->group(function (): void {
    $site = fn (Request $request): Site => Site::query()->whereKey($request->query('site'))->first()
        ?? Site::query()->where('name', ReportDemoSeeder::SITE_NAME)->firstOrFail();
    $summary = function (Site $site, string $type): \Waypost\Reports\Summary {
        $period = ReportPeriod::latest(ReportFrequency::from($type), $site->timezone, Time::now());

        return app(Summarizer::class)->period($site, $period->fromDate, $period->toDate);
    };

    Route::get('/', fn () => response(implode('', array_map(
        fn (string $page): string => "<p><a href=\"/reports/{$page}\">{$page}</a></p>",
        ['shift', 'daily', 'weekly', 'weekly.pdf', 'monthly', 'monthly.pdf', 'no-data'],
    ))));

    Route::get('shift', function (Request $request) use ($site) {
        $shift = Shift::query()->where('site_id', $site($request)->id)->whereNotNull('ended_at')->latest('started_at')->firstOrFail();

        return view('waypost::mail.shift-report', ReportContent::shift(app(Summarizer::class)->shift($shift), $shift));
    });

    Route::get('{type}', fn (Request $request, string $type) => view(
        'waypost::mail.period-report',
        ReportContent::period($summary($site($request), $type), ReportFrequency::from($type)),
    ))->whereIn('type', ['daily', 'weekly', 'monthly']);

    Route::get('{type}.pdf', fn (Request $request, string $type) => response(
        SendPeriodReport::pdf($summary($site($request), $type), ReportFrequency::from($type)),
        200,
        ['Content-Type' => 'application/pdf'],
    ))->whereIn('type', ['weekly', 'monthly']);

    // As if the phone had been silent since the last round of last night was due.
    Route::get('no-data', function (Request $request) use ($site, $summary) {
        $site = $site($request);
        $round = collect($summary($site, 'daily')->rounds)->filter(fn (RoundRow $row): bool => $row->expected)->last()
            ?? abort(404, 'No expected round last night.');
        $phone = Device::query()->where('site_id', $site->id)->latest('enrolled_at')->first();
        $alert = new NoDataAlert(['last_seen_at' => $phone?->last_seen_at?->min($round->deadline->subMinutes(20))]);

        return view('waypost::mail.no-data-alert', SendNoDataAlerts::content($alert, $site, $round, $round->deadline->addMinutes($site->no_data_alert_after_min)));
    });
});
