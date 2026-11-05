<?php

declare(strict_types=1);

namespace Waypost\Reports;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Waypost\Enums\ReportFrequency;
use Waypost\Enums\Role;
use Waypost\Mail\ReportMail;
use Waypost\Models\Report;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Logger;
use Waypost\Support\Time;

/**
 * Sends a report once (spec: Jelentések): to the site's supervisors who subscribed to its type, one e-mail each,
 * and records it in the reports table; a weekly or monthly report's PDF is stored for the archive (GET /reports).
 *
 * The reports row is claimed first (unique by site, type and period start), so two jobs of the same report send it
 * once; if the sending fails, the claim is undone, and the job's retry sends it again. A job that dies instead (out of
 * memory, a timeout, a killed worker) cannot undo it: a claim not sent within CLAIM_TIMEOUT_MIN counts as abandoned,
 * and the next attempt claims it again (SendDueReports dispatches a due report again, SendShiftReport retries).
 *
 * The recipients sent to are written to the claim after each e-mail (delivered_to). A failure after some of them
 * gives the claim up at once instead of undoing it, and the next claim takes them over, as it does from an abandoned
 * one: nobody gets the report twice, but the one whose sending the job died in.
 */
final class ReportSender
{
    public const string DIRECTORY = 'waypost/reports';

    /** Minutes after which a claim not sent is abandoned: far beyond a report's sending (a queue job's timeout). */
    public const int CLAIM_TIMEOUT_MIN = 15;

    /**
     * The addresses of the site's active supervisors who subscribed to the type, in order.
     *
     * @return list<string>
     */
    public static function recipients(Site $site, ReportFrequency $type): array
    {
        return array_values(array_map(strval(...), User::query()
            ->where('role', Role::Supervisor->value)
            ->where('active', true)
            ->whereNotNull('email')
            ->whereHas('sites', fn (Builder $sites) => $sites->whereKey($site->id))
            ->whereHas('reportSubscriptions', fn (Builder $subscriptions) => $subscriptions
                ->where('site_id', $site->id)
                ->where('frequency', $type->value))
            ->orderBy('email')
            ->pluck('email')
            ->all()));
    }

    /**
     * @param  Closure(Report, ?string): ReportMail  $mail  a new e-mail for each recipient, with the PDF if there is one
     * @param  (Closure(Report): string)|null  $pdf  the PDF to store and attach
     * @return Report|null  null: already sent (or being sent), or nobody to send it to
     */
    public function send(Site $site, ReportFrequency $type, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, Closure $mail, ?Closure $pdf = null): ?Report
    {
        $recipients = self::recipients($site, $type);
        if ($recipients === [] || self::exists($site, $type, $periodStart)) {
            return null;
        }
        $delivered = self::releaseAbandoned($site, $type, $periodStart);

        try {
            // A savepoint: on PostgreSQL the failed insert would abort an outer transaction.
            $report = DB::transaction(fn (): Report => Report::query()->create([
                'site_id' => $site->id,
                'type' => $type,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'generated_at' => Time::now(),
                'recipients' => $recipients,
                'delivered_to' => $delivered === [] ? null : $delivered,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $disk = Storage::disk(config('waypost.storage.disk'));
        try {
            $file = null;
            if ($pdf !== null) {
                $file = $pdf($report);
                $path = self::DIRECTORY . "/{$report->id}.pdf";
                $disk->put($path, $file, 'private');
                $report->path = $path;
            }
            foreach (array_diff($recipients, $delivered) as $address) {
                Mail::to($address)->send($mail($report, $file));
                $delivered[] = $address;
                $report->fill(['delivered_to' => $delivered])->save();
            }
            $report->fill(['sent_at' => Time::now()])->save();
        } catch (Throwable $e) {
            if ($report->delivered_to === null) {
                if ($report->path !== null) {
                    $disk->delete($report->path);
                }
                $report->delete();
            } else {
                // Given up, as if abandoned: the retry claims it again and sends to the rest.
                $report->fill(['generated_at' => Time::now()->subMinutes(self::CLAIM_TIMEOUT_MIN)->subSecond()])->save();
            }

            throw $e;
        }

        return $report;
    }

    /** Sent already, or being sent (claimed, and not abandoned yet). */
    public static function exists(Site $site, ReportFrequency $type, CarbonImmutable $periodStart): bool
    {
        return self::claim($site, $type, $periodStart)
            ->where(fn (Builder $report) => $report->whereNotNull('sent_at')->orWhere('generated_at', '>', self::abandonedBefore()))
            ->exists();
    }

    /**
     * Seconds until the claim of a report being sent is abandoned, if it is being sent: a job meeting it after its
     * own attempt died waits this long, then claims the report again.
     */
    public static function pendingFor(Site $site, ReportFrequency $type, CarbonImmutable $periodStart): ?int
    {
        $claim = self::claim($site, $type, $periodStart)->whereNull('sent_at')->first();
        if ($claim === null) {
            return null;
        }
        $abandonedAt = $claim->generated_at->getTimestamp() + self::CLAIM_TIMEOUT_MIN * 60;

        return max(1, $abandonedAt - Time::now()->getTimestamp() + 1);
    }

    /**
     * Deletes the abandoned or given up claim of the report (and the PDF it may have stored), so it can be claimed
     * again.
     *
     * @return list<string> the recipients the claim was sent to already
     */
    private static function releaseAbandoned(Site $site, ReportFrequency $type, CarbonImmutable $periodStart): array
    {
        $abandoned = self::claim($site, $type, $periodStart)->whereNull('sent_at')->where('generated_at', '<=', self::abandonedBefore())->first();
        if ($abandoned === null) {
            return [];
        }

        $delivered = $abandoned->delivered_to ?? [];
        Logger::channel()->warning('Waypost: a report claimed but not sent to everyone is sent again, to the rest; its previous attempt failed or died.', [
            'report_id' => $abandoned->id, 'site_id' => $site->id, 'type' => $type->value, 'generated_at' => Time::iso($abandoned->generated_at),
            'delivered_to' => $delivered,
        ]);
        if ($abandoned->path !== null) {
            Storage::disk(config('waypost.storage.disk'))->delete($abandoned->path);
        }
        // By its ID: a concurrent job may have released it and claimed the report anew already.
        Report::query()->whereKey($abandoned->id)->delete();

        return $delivered;
    }

    /** @return Builder<Report> */
    private static function claim(Site $site, ReportFrequency $type, CarbonImmutable $periodStart): Builder
    {
        return Report::query()
            ->where('site_id', $site->id)
            ->where('type', $type->value)
            ->where('period_start', $periodStart->utc()->format(Time::DATABASE_FORMAT));
    }

    private static function abandonedBefore(): string
    {
        return Time::now()->subMinutes(self::CLAIM_TIMEOUT_MIN)->format(Time::DATABASE_FORMAT);
    }
}
