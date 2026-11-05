<?php

declare(strict_types=1);

namespace Waypost\Jobs;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Waypost\Enums\Role;
use Waypost\Mail\NoDataAlertMail;
use Waypost\Models\Device;
use Waypost\Models\NoDataAlert;
use Waypost\Models\Route;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Reports\LocalFormat;
use Waypost\Reports\RoundRow;
use Waypost\Reports\Summarizer;
use Waypost\Support\Time;

/**
 * The "Nincs adat" alert (spec: „Nincs adat” figyelmeztetés), every 15 minutes: when no_data_alert_after_min minutes
 * have passed since the deadline of a site's last expected round, the server has no round for it, and the site's
 * phone has not synced since that deadline, an e-mail goes to every supervisor of the site; at most once a schedule
 * window (no_data_alerts, unique by site and the window's local start day).
 *
 * Only a site with a phone: one enrolled before the deadline, not revoked, not deleted. A site without a phone sends
 * nothing to wait for; its rounds are missed in the reports.
 */
final class SendNoDataAlerts implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /** Seconds the unique lock lasts at most: a run that died does not block the next ones for longer. */
    public int $uniqueFor = 900;

    public int $tries = 3;

    public function __construct()
    {
        $this->onConnection(config('waypost.queue.connection'));
        $this->onQueue(config('waypost.queue.queue'));
    }

    public function handle(Summarizer $summarizer): void
    {
        $now = Time::now();
        $sites = Site::query()
            ->whereHas('devices', fn (Builder $devices) => $devices->whereNull('revoked_at'))
            ->orderBy('id')
            ->get();
        foreach ($sites as $site) {
            $this->check($site, $now, $summarizer);
        }
    }

    private function check(Site $site, CarbonImmutable $now, Summarizer $summarizer): void
    {
        $today = $now->setTimezone($site->timezone)->format('Y-m-d');
        $threshold = $now->subMinutes($site->no_data_alert_after_min);
        // A window starts at most a day before its rounds end.
        $summary = $summarizer->period($site, $now->setTimezone($site->timezone)->subDay()->format('Y-m-d'), $today, $now);
        $last = collect($summary->rounds)
            ->filter(fn (RoundRow $row): bool => $row->expected && $row->deadline->lessThanOrEqualTo($threshold))
            ->sortBy(fn (RoundRow $row): int => Time::milliseconds($row->deadline))
            ->last();
        if (!$last instanceof RoundRow || $last->round !== null) {
            return;
        }

        $phones = Device::query()
            ->where('site_id', $site->id)
            ->whereNull('revoked_at')
            ->where('enrolled_at', '<', $last->deadline->format(Time::DATABASE_FORMAT))
            ->get();
        $lastSeen = (int) $phones->max(fn (Device $phone): int => $phone->last_seen_at === null ? 0 : Time::milliseconds($phone->last_seen_at));
        if ($phones->isEmpty() || $lastSeen >= Time::milliseconds($last->deadline)) {
            return;
        }
        if (NoDataAlert::query()->where('site_id', $site->id)->where('window_day', $last->startDay)->exists()) {
            return;
        }
        $recipients = self::recipients($site);
        if ($recipients === []) {
            return;
        }

        try {
            // A savepoint: on PostgreSQL the failed insert would abort an outer transaction.
            $alert = DB::transaction(fn (): NoDataAlert => NoDataAlert::query()->create([
                'site_id' => $site->id,
                'window_day' => $last->startDay,
                'scheduled_at' => $last->scheduledAt,
                'deadline' => $last->deadline,
                'last_seen_at' => $lastSeen === 0 ? null : Time::fromMilliseconds($lastSeen),
                'recipients' => $recipients,
            ]));
        } catch (UniqueConstraintViolationException) {
            return;
        }

        try {
            $content = self::content($alert, $site, $last, $now);
            foreach ($recipients as $address) {
                Mail::to($address)->send(new NoDataAlertMail($alert, $content));
            }
            $alert->fill(['sent_at' => Time::now()])->save();
        } catch (Throwable $e) {
            $alert->delete();

            throw $e;
        }
    }

    /**
     * The addresses of the site's active supervisors, in order: the alert is the site's setting, not a subscription.
     *
     * @return list<string>
     */
    public static function recipients(Site $site): array
    {
        return array_values(array_map(strval(...), User::query()
            ->where('role', Role::Supervisor->value)
            ->where('active', true)
            ->whereNotNull('email')
            ->whereHas('sites', fn (Builder $sites) => $sites->whereKey($site->id))
            ->orderBy('email')
            ->pluck('email')
            ->all()));
    }

    /** @return array<string, mixed> resources/views/mail/no-data-alert.blade.php */
    public static function content(NoDataAlert $alert, Site $site, RoundRow $round, CarbonImmutable $now): array
    {
        $format = new LocalFormat($site->timezone);
        $at = fn (DateTimeInterface $time): string => $format->dateOf($time) . ' ' . $format->time($time);
        $route = Route::withTrashed()->find($round->routeId);
        $elapsed = intdiv(max(0, $now->getTimestamp() - $round->deadline->getTimestamp()), 60);

        return [
            'title' => 'Figyelmeztetés',
            'site' => ['name' => $site->name],
            'last_seen' => $alert->last_seen_at === null ? null : $at($alert->last_seen_at),
            'round_label' => $at($round->scheduledAt) . ($route === null ? '' : " ({$route->name})"),
            'deadline' => $format->time($round->deadline),
            'elapsed' => self::duration($elapsed),
            'after' => self::duration($site->no_data_alert_after_min),
        ];
    }

    /** 45 perc, 2 óra, 2 óra 30 perc */
    private static function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours === 0 => "{$rest} perc",
            $rest === 0 => "{$hours} óra",
            default => "{$hours} óra {$rest} perc",
        };
    }
}
