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
use Waypost\Support\Time;

/**
 * Sends a report once (spec: Jelentések): to the site's supervisors who subscribed to its type, one e-mail each,
 * and records it in the reports table; a weekly or monthly report's PDF is stored for the archive (GET /reports).
 *
 * The reports row is claimed first (unique by site, type and period start), so two jobs of the same report send it
 * once; if the sending fails, the claim is undone, and the job's retry sends it again.
 */
final class ReportSender
{
    public const string DIRECTORY = 'waypost/reports';

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

        try {
            // A savepoint: on PostgreSQL the failed insert would abort an outer transaction.
            $report = DB::transaction(fn (): Report => Report::query()->create([
                'site_id' => $site->id,
                'type' => $type,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'generated_at' => Time::now(),
                'recipients' => $recipients,
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
            foreach ($recipients as $address) {
                Mail::to($address)->send($mail($report, $file));
            }
            $report->fill(['sent_at' => Time::now()])->save();
        } catch (Throwable $e) {
            if ($report->path !== null) {
                $disk->delete($report->path);
            }
            $report->delete();

            throw $e;
        }

        return $report;
    }

    public static function exists(Site $site, ReportFrequency $type, CarbonImmutable $periodStart): bool
    {
        return Report::query()
            ->where('site_id', $site->id)
            ->where('type', $type->value)
            ->where('period_start', $periodStart->utc()->format(Time::DATABASE_FORMAT))
            ->exists();
    }
}
