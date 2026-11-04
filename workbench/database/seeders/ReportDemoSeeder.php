<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Waypost\Chain\ChainProcessor;
use Waypost\Enums\OrderMode;
use Waypost\Enums\ReportFrequency;
use Waypost\Enums\ScheduleMode;
use Waypost\Models\Checkpoint;
use Waypost\Models\Device;
use Waypost\Models\Media;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Route;
use Waypost\Models\RouteVersion;
use Waypost\Models\RouteVersionCheckpoint;
use Waypost\Models\Schedule;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Time;
use Waypost\Support\Uuid;
use Waypost\Tests\Fixtures\ChainBuilder;

/**
 * A patrolled site with weeks of history, for looking at the reports (M6-12): a phone that pushed a signed chain every
 * morning through the server's own ChainProcessor, so the projections, the server's flags and the photos are real.
 * Rounds at 21, 23, 01, 03 and 05 o'clock, mostly done, some incomplete, missed or with a flag, incidents with photos,
 * a handover, a night the phone was off. The workbench's /reports pages show the reports from it.
 *
 * Not part of the build (the app's tests use the demo site alone): `composer seed-reports` after `composer build`.
 */
final class ReportDemoSeeder extends Seeder
{
    public const string SITE_NAME = 'Ipari park, B épület';

    public const string SUPERVISOR_EMAIL = 'riport@waypost.test';

    public const array GUARDS = ['Nagy Péter', 'Kiss Gábor'];

    public const array CHECKPOINTS = ['Főbejárat', 'Raktár 2 – vészkijárat', 'Kazánház', 'Hátsó kapu'];

    /** Seconds between neighbours in the training; a scan much quicker than this is too_fast. */
    private const int WALK_S = 150;

    private ChainBuilder $chain;

    private Device $phone;

    /** @var list<Checkpoint> */
    private array $checkpoints = [];

    private RouteVersion $version;

    private Schedule $schedule;

    /** @var list<User> */
    private array $guards = [];

    /** @var array<string, string> the bytes of the photos cited, by ID, until they are uploaded */
    private array $photos = [];

    /** @param  int  $nights  how many nights before today have a history */
    public function __construct(public readonly int $nights = 40)
    {
    }

    public function run(): void
    {
        mt_srand(2026);
        $today = Time::now()->setTimezone('Europe/Budapest')->startOfDay();
        $first = $today->subDays($this->nights + 1);
        CarbonImmutable::setTestNow($first->utc());

        try {
            $this->configure();
            for ($night = $first->addDay(); $night->lessThan($today); $night = $night->addDay()) {
                $this->patrol($night);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }

        // Last: the shift reports of the history are not sent.
        $supervisor = User::query()->where('email', DatabaseSeeder::SUPERVISOR_EMAIL)->first();
        foreach (array_filter([$supervisor, User::query()->where('email', self::SUPERVISOR_EMAIL)->first()]) as $user) {
            foreach (ReportFrequency::cases() as $frequency) {
                ReportSubscription::factory()->create(['user_id' => $user->id, 'site_id' => $this->phone->site_id, 'frequency' => $frequency]);
            }
        }
        $this->command?->info(sprintf('%s: %d nights of patrols; reports at /reports', self::SITE_NAME, $this->nights));
    }

    private function configure(): void
    {
        $supervisor = User::factory()->supervisor()->create(['name' => 'Riport Supervisor', 'email' => self::SUPERVISOR_EMAIL]);
        $site = Site::factory()->for($supervisor, 'creator')->create(['name' => self::SITE_NAME, 'address' => '2040 Budaörs, Ipari park 7.']);
        $site->users()->attach($supervisor);
        $demoSupervisor = User::query()->where('email', DatabaseSeeder::SUPERVISOR_EMAIL)->first();
        if ($demoSupervisor !== null) {
            $site->users()->attach($demoSupervisor);
        }
        foreach (self::GUARDS as $name) {
            $guard = User::factory()->create(['name' => $name]);
            $site->users()->attach($guard);
            $this->guards[] = $guard;
        }

        $route = Route::factory()->for($site)->create(['name' => 'Éjszakai kör', 'order_mode' => OrderMode::Fixed]);
        $this->version = RouteVersion::factory()->for($route)->for($supervisor, 'creator')->create(['walk_duration_s' => self::WALK_S * 3]);
        foreach (self::CHECKPOINTS as $index => $name) {
            // About 40 m apart, trained to ±5 m.
            $checkpoint = Checkpoint::factory()->for($site)->create([
                'name' => $name, 'lat_e7' => 474_500_000 + $index * 3_000, 'lng_e7' => 189_500_000 + $index * 3_000, 'accuracy_dm' => 50,
            ]);
            RouteVersionCheckpoint::factory()->for($this->version)->for($checkpoint)->create([
                'position' => $index + 1,
                'walk_time_from_prev_s' => $index === 0 ? null : self::WALK_S,
            ]);
            $this->checkpoints[] = $checkpoint;
        }
        $route->update(['current_version_id' => $this->version->id]);
        $this->schedule = Schedule::factory()->for($route)->create([
            'name' => 'Éjszaka kétóránként',
            'mode' => ScheduleMode::Interval,
            'start_times' => null,
            'interval_from' => '21:00',
            'interval_to' => '05:00',
            'interval_every_min' => 120,
            'time_limit_min' => 30,
        ]);

        $this->phone = Device::factory()->for($site)->create(['name' => 'Portafülke', 'enrolled_by' => $supervisor->id]);
        $this->chain = new ChainBuilder($this->phone);
    }

    /** A night from 20:50 to 05:50 (local), pushed the next morning at 06:10. */
    private function patrol(CarbonImmutable $day): void
    {
        // Wall-clock times of the night, also across a DST change; 25 is 01:00 the next day.
        $at = fn (int $hour, int $minute = 0): CarbonImmutable => $day->addDays(intdiv($hour, 24))->setTime($hour % 24, $minute)->utc();
        $roll = mt_rand(1, 100);
        if ($roll <= 3) {
            return; // The phone was off: every round is missed, the reports show it.
        }

        $entries = [];
        $guard = $this->guards[$day->dayOfYear % 2];
        $started = $this->entry($at(20, 50), fn (array $clock) => $this->chain->shiftStarted($guard, $clock));
        $entries[] = $started;
        $shiftId = $started['payload']['shift_id'];
        $handover = $roll <= 6;

        foreach ([21, 23, 25, 27, 29] as $hour) {
            if ($handover && $hour === 25) {
                // The other guard takes over at 00:40.
                $entries[] = $this->entry($at(24, 40), fn (array $clock) => $this->chain->shiftClosed($shiftId, 'handover', $clock));
                $guard = $this->guards[($day->dayOfYear + 1) % 2];
                $next = $this->entry($at(24, 40), fn (array $clock) => $this->chain->shiftStarted($guard, $clock));
                $entries[] = $next;
                $shiftId = $next['payload']['shift_id'];
            }
            array_push($entries, ...$this->round($shiftId, $at($hour)));
        }
        if (mt_rand(1, 6) === 1) {
            $entries[] = $this->incident($shiftId, $at(26, 12), 'open_door', 'A raktár vészkijárata nyitva volt, bezártam.');
        }
        $closedBy = mt_rand(1, 5) === 1 ? 'auto' : 'guard';
        $entries[] = $this->entry($at(29, 50), fn (array $clock) => $this->chain->shiftClosed($shiftId, $closedBy, $clock));

        CarbonImmutable::setTestNow($at(30, 10));
        app(ChainProcessor::class)->process($this->phone->refresh(), $entries);
        $this->uploadPhotos();
    }

    /** @return list<array<string, mixed>> */
    private function round(string $shiftId, CarbonImmutable $scheduledAt): array
    {
        $roundId = Uuid::v7();
        $entries = [$this->entry($scheduledAt, fn (array $clock) => $this->chain->entry('round_opened', [
            'round_id' => $roundId, 'shift_id' => $shiftId, 'route_version_id' => $this->version->id,
            'schedule_id' => $this->schedule->id, 'scheduled_at' => Time::iso($scheduledAt), 'time_limit_min' => 30,
        ], $clock))];

        $kind = mt_rand(1, 100);
        $deadline = $scheduledAt->addMinutes(30);
        if ($kind <= 4) {
            $entries[] = $this->entry($deadline, fn (array $clock) => $this->chain->entry('round_closed', ['round_id' => $roundId, 'status' => 'missed', 'finished_at' => Time::iso($deadline)], $clock));

            return $entries;
        }

        $points = $this->checkpoints;
        $skipped = $kind <= 11 ? mt_rand(1, 3) : null;
        $time = $scheduledAt->addMinutes(mt_rand(1, 6));
        foreach ($points as $index => $checkpoint) {
            if ($index === $skipped) {
                continue;
            }
            if ($index > 0) {
                // Now and then far too quick (too_fast), otherwise about the trained walk.
                $time = $time->addSeconds($kind > 95 && $index === 2 ? 40 : mt_rand(130, 200));
            }
            $scanAt = $time;
            if ($kind > 11 && $kind <= 15 && $index === 1) {
                $entries[] = $this->manualScan($roundId, $shiftId, $checkpoint, $scanAt, $entries);

                continue;
            }
            // Now and then the GNSS clock disagrees with the phone's (time_suspect).
            $fields = $kind > 92 && $kind <= 95 && $index === 3 ? ['gnss_time' => Time::iso($scanAt->addMinutes(9))] : [];
            // Now and then about 170 m off (far_from_checkpoint), otherwise within a few metres.
            $offset = $kind > 89 && $kind <= 92 && $index === 2 ? 15_000 : mt_rand(-150, 150);
            $entries[] = $this->entry($scanAt, fn (array $clock) => $this->chain->scan($roundId, $checkpoint->id, ['lat_e7' => (int) $checkpoint->lat_e7 + $offset,
                'lng_e7' => (int) $checkpoint->lng_e7 + mt_rand(-150, 150), 'accuracy_dm' => mt_rand(30, 90)], [...$clock, ...$fields]));
        }
        $status = $skipped === null ? 'completed' : 'incomplete';
        $finished = $skipped === null ? $time : $deadline;
        $entries[] = $this->entry($finished, fn (array $clock) => $this->chain->entry('round_closed', ['round_id' => $roundId, 'status' => $status, 'finished_at' => Time::iso($finished)], $clock));

        return $entries;
    }

    /**
     * A manual scan (a damaged tag) with its photo, and the damaged_tag incident citing it: two entries, the scan
     * returned, the incident appended to $entries after it.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function manualScan(string $roundId, string $shiftId, Checkpoint $checkpoint, CarbonImmutable $at, array &$entries): array
    {
        $photo = $this->photo(40);
        $scan = $this->entry($at, fn (array $clock) => $this->chain->scan($roundId, $checkpoint->id, [
            'method' => 'manual', 'tag_uid' => null, 'flags' => ['manual'], 'note' => 'A címke leesett, a tartóját is letörték.', 'media' => [$photo],
        ], $clock));
        $entries[] = $scan;

        return $this->entry($at, fn (array $clock) => $this->chain->incident($shiftId, [
            'category' => 'damaged_tag', 'note' => 'A címke leesett, a tartóját is letörték.', 'scan_id' => $scan['payload']['scan_id'],
            'nearest_checkpoint_id' => $checkpoint->id, 'media' => [$photo],
        ], $clock));
    }

    /** @return array<string, mixed> */
    private function incident(string $shiftId, CarbonImmutable $at, string $category, string $note): array
    {
        return $this->entry($at, fn (array $clock) => $this->chain->incident($shiftId, [
            'category' => $category, 'note' => $note, 'nearest_checkpoint_id' => $this->checkpoints[1]->id,
            'lat_e7' => 474_503_000, 'lng_e7' => 189_503_000, 'accuracy_dm' => 60, 'media' => [$this->photo(200), $this->photo(120)],
        ], $clock));
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $write
     * @return array<string, mixed>
     */
    private function entry(CarbonImmutable $at, callable $write): array
    {
        return $write(['device_time' => Time::iso($at), 'uptime_ms' => 1_000_000 + $this->chain->seq * 1_000]);
    }

    /** @return array{id: string, sha256: string} a photo for an entry's media */
    private function photo(int $red): array
    {
        $image = imagecreatetruecolor(1600, 1200) ?: throw new LogicException('GD cannot create an image.');
        imagefill($image, 0, 0, (int) imagecolorallocate($image, max(0, min(255, $red)), 90, 60));
        imagefilledrectangle($image, 500, 350, 1100, 850, (int) imagecolorallocate($image, 230, 230, 220));
        ob_start();
        imagejpeg($image, null, 80);
        $bytes = (string) ob_get_clean();
        $id = Uuid::v7();
        $this->photos[$id] = $bytes;

        return ['id' => $id, 'sha256' => hash('sha256', $bytes)];
    }

    /** The photos of the night, as the phone uploads them after the push (MediaController stores them so). */
    private function uploadPhotos(): void
    {
        $disk = Storage::disk(config('waypost.storage.disk'));
        foreach ($this->photos as $id => $bytes) {
            $path = "waypost/media/{$id}.jpg";
            $disk->put($path, $bytes, 'private');
            Media::query()->whereKey($id)->update([
                'mime' => 'image/jpeg', 'size_bytes' => strlen($bytes), 'path' => $path, 'uploaded_at' => Time::now()->format(Time::DATABASE_FORMAT),
            ]);
        }
        $this->photos = [];
    }
}
