<?php

declare(strict_types=1);

namespace Waypost\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Waypost\Enums\ReportFrequency;
use Waypost\Mail\ReportMail;
use Waypost\Models\Report;
use Waypost\Models\Site;
use Waypost\Reports\Labels;
use Waypost\Reports\Pdf;
use Waypost\Reports\ReportContent;
use Waypost\Reports\ReportSender;
use Waypost\Reports\Summarizer;
use Waypost\Reports\Thumbnails;

/**
 * A daily, weekly or monthly report of a site (spec: Jelentések), from the data as it is now: HTML e-mail, and for the
 * weekly and monthly one a PDF with the photos embedded, attached and stored. SendDueReports dispatches it.
 */
final class SendPeriodReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly string $siteId,
        public readonly ReportFrequency $type,
        public readonly string $fromDate,
        public readonly string $toDate,
    ) {
        $this->onConnection(config('waypost.queue.connection'));
        $this->onQueue(config('waypost.queue.queue'));
    }

    public function handle(Summarizer $summarizer, ReportSender $sender): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            return;
        }
        $summary = $summarizer->period($site, $this->fromDate, $this->toDate);
        if (ReportSender::exists($site, $this->type, $summary->periodStart)) {
            return;
        }

        $content = ReportContent::period($summary, $this->type);
        $title = Labels::report($this->type);
        $subject = "{$title} – {$site->name} – {$content['period_label']}" . ($summary->isIncomplete() ? ' (nem teljes adat)' : '');
        $withPdf = in_array($this->type, [ReportFrequency::Weekly, ReportFrequency::Monthly], true);
        $pdfName = 'waypost-' . Str::slug("{$title} {$site->name} {$this->fromDate}") . '.pdf';

        $sender->send(
            $site,
            $this->type,
            $summary->periodStart,
            $summary->periodEnd,
            fn (Report $report, ?string $pdf): ReportMail => new ReportMail($report, 'waypost::mail.period-report', $content, $subject, $pdf, $pdfName),
            $withPdf ? fn (): string => Pdf::render(
                view('waypost::pdf.period-report', ReportContent::period($summary, $this->type, Thumbnails::dataUri(...)))->render(),
                "Waypost · {$site->name} · {$title} · {$content['period_label']}",
            ) : null,
        );
    }
}
