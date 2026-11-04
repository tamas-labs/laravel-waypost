<?php

declare(strict_types=1);

namespace Waypost\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Waypost\Enums\ReportFrequency;
use Waypost\Mail\ReportMail;
use Waypost\Models\Device;
use Waypost\Models\Report;
use Waypost\Models\Shift;
use Waypost\Models\Site;
use Waypost\Reports\IncompleteReason;
use Waypost\Reports\ReportContent;
use Waypost\Reports\ReportSender;
use Waypost\Reports\Summarizer;
use Waypost\Support\Time;

/**
 * The shift report (spec: Jelentések): dispatched when the server applies a shift_closed entry, it waits at most
 * PHOTO_WAIT_MIN minutes for the shift's photos, checking every RECHECK_S seconds, then sends what is there. A shift
 * report goes out once: data arriving later does not send it again (the daily, weekly and monthly ones have it).
 *
 * A shift whose phone was replaced before the guard closed it never gets its shift_closed: its report is dispatched
 * when the revoked phone closes its last upload, and it ends with the phone's last entry.
 */
final class SendShiftReport implements ShouldQueue
{
    use Queueable;

    public const int PHOTO_WAIT_MIN = 30;

    public const int RECHECK_S = 60;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly string $shiftId, public readonly string $photosDueAt)
    {
        $this->onConnection(config('waypost.queue.connection'));
        $this->onQueue(config('waypost.queue.queue'));
    }

    /** Dispatches the report of a shift just closed (or left open by a replaced phone), after the transaction. */
    public static function after(Shift $shift): void
    {
        dispatch(new self($shift->id, Time::iso(Time::now()->addMinutes(self::PHOTO_WAIT_MIN))))->afterCommit();
    }

    public function handle(Summarizer $summarizer, ReportSender $sender): void
    {
        $shift = Shift::query()->find($this->shiftId);
        $site = $shift === null ? null : Site::query()->find($shift->site_id);
        if ($shift === null || $site === null || ReportSender::exists($site, ReportFrequency::Shift, $shift->started_at->toImmutable())) {
            return;
        }
        $device = Device::withTrashed()->find($shift->device_id);
        // A revoked phone that closed its last upload sends no more photos.
        $uploading = $device === null || $device->upload_closed_at === null;
        if ($shift->ended_at === null && $uploading) {
            return;
        }

        $summary = $summarizer->shift($shift);
        if ($uploading && in_array(IncompleteReason::MissingPhotos, $summary->incompleteReasons, true) && $this->canWait()) {
            $remaining = Time::fromIso($this->photosDueAt)->getTimestamp() - Time::now()->getTimestamp();
            dispatch(new self($this->shiftId, $this->photosDueAt))->delay(min(self::RECHECK_S, max(1, $remaining)));

            return;
        }

        $content = ReportContent::shift($summary, $shift);
        $subject = "Műszakjelentés – {$site->name} – {$content['shift']['date_label']} {$content['shift']['from']}–{$content['shift']['to']}"
            . ($summary->isIncomplete() ? ' (nem teljes adat)' : '');
        $sender->send(
            $site,
            ReportFrequency::Shift,
            $summary->periodStart,
            $summary->periodEnd,
            fn (Report $report): ReportMail => new ReportMail($report, 'waypost::mail.shift-report', $content, $subject),
        );
    }

    /** Before the photos are due, on a queue that can delay a job (the sync one runs it at once, again and again). */
    private function canWait(): bool
    {
        $connection = $this->connection ?? config('queue.default');

        return Time::now()->lessThan(Time::fromIso($this->photosDueAt))
            && config("queue.connections.{$connection}.driver") !== 'sync';
    }
}
