<?php

declare(strict_types=1);

namespace Waypost\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Waypost\Enums\ReportFrequency;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Site;
use Waypost\Reports\ReportPeriod;
use Waypost\Reports\ReportSender;
use Waypost\Reports\Summarizer;
use Waypost\Support\Time;

/**
 * Dispatches the daily, weekly and monthly reports that are due (ReportPeriod: 10:00 on each site's own clock) and
 * not sent yet, for the sites with a subscription to them. Scheduled every 15 minutes by the service provider, so
 * every time zone's 10:00 is met, and a missed run is made up by the next one.
 */
final class SendDueReports implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /** Seconds the unique lock lasts at most: a run that died does not block the next ones for longer. */
    public int $uniqueFor = 900;

    public const array TYPES = [ReportFrequency::Daily, ReportFrequency::Weekly, ReportFrequency::Monthly];

    public function __construct()
    {
        $this->onConnection(config('waypost.queue.connection'));
        $this->onQueue(config('waypost.queue.queue'));
    }

    public function handle(): void
    {
        $now = Time::now();
        $subscribed = ReportSubscription::query()
            ->whereIn('frequency', array_map(fn (ReportFrequency $type): string => $type->value, self::TYPES))
            ->distinct()
            ->get(['site_id', 'frequency'])
            ->groupBy('site_id');

        foreach (Site::query()->whereIn('id', $subscribed->keys()->all())->orderBy('id')->get() as $site) {
            foreach ($subscribed->get($site->id, collect())->pluck('frequency')->unique() as $type) {
                /** @var ReportFrequency $type */
                $period = ReportPeriod::due($type, $site->timezone, $now);
                if ($period === null || ReportSender::exists($site, $type, Summarizer::midnight($period->fromDate, $site->timezone))) {
                    continue;
                }
                dispatch(new SendPeriodReport($site->id, $type, $period->fromDate, $period->toDate));
            }
        }
    }
}
