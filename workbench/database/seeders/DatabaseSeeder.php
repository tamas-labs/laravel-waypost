<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Waypost\Enums\OrderMode;
use Waypost\Enums\ScheduleMode;
use Waypost\Enums\TagType;
use Waypost\Models\Checkpoint;
use Waypost\Models\Route;
use Waypost\Models\RouteVersion;
use Waypost\Models\RouteVersionCheckpoint;
use Waypost\Models\Schedule;
use Waypost\Models\Site;
use Waypost\Models\User;

/**
 * Demo data for local development and the app's M3 tests: one site with a supervisor, two guards with
 * known PINs, six checkpoints (NFC and QR), a fixed and a free order route, and a schedule every 5 minutes.
 */
final class DatabaseSeeder extends Seeder
{
    public const string SUPERVISOR_EMAIL = 'supervisor@waypost.test';

    public const string SUPERVISOR_PASSWORD = 'password';

    public const string SUPERVISOR_PIN = '111111';

    /** Guard names and PINs. */
    public const array GUARDS = [
        'Kiss Péter' => '222222',
        'Nagy Anna' => '333333',
    ];

    /** Checkpoint names with the NFC tag UID, or null for a QR checkpoint. */
    public const array CHECKPOINTS = [
        'Főbejárat' => '04A1B2C3D4E501',
        'Raktár' => '04A1B2C3D4E502',
        'Hátsó kapu' => '04A1B2C3D4E503',
        'Parkoló' => '04A1B2C3D4E504',
        'Kazánház' => null,
        'Tetőfeljáró' => null,
    ];

    public function run(): void
    {
        $supervisor = User::factory()->supervisor()->withPin(self::SUPERVISOR_PIN)->create([
            'name' => 'Demó Supervisor',
            'email' => self::SUPERVISOR_EMAIL,
            'password' => self::SUPERVISOR_PASSWORD,
        ]);

        $site = Site::factory()->for($supervisor, 'creator')->create([
            'name' => 'Demó telephely',
            'address' => '1051 Budapest, Példa utca 1.',
        ]);
        $site->users()->attach($supervisor);

        foreach (self::GUARDS as $name => $pin) {
            $site->users()->attach(User::factory()->withPin($pin)->create(['name' => $name]));
        }

        $checkpoints = [];
        foreach (self::CHECKPOINTS as $name => $tagUid) {
            $factory = Checkpoint::factory()->for($site);
            $checkpoints[] = ($tagUid === null ? $factory->qr() : $factory)->create([
                'name' => $name,
                'tag_type' => $tagUid === null ? TagType::Qr : TagType::Nfc,
                'tag_uid' => $tagUid,
            ]);
        }

        $fixed = $this->route($site, $supervisor, 'Külső kör', OrderMode::Fixed, array_slice($checkpoints, 0, 4));
        $this->route($site, $supervisor, 'Belső kör', OrderMode::Free, array_slice($checkpoints, 2, 4));

        Schedule::factory()->for($fixed)->create([
            'name' => 'Ötpercenként',
            'mode' => ScheduleMode::Interval,
            'start_times' => null,
            'interval_from' => '00:00',
            'interval_to' => '23:55',
            'interval_every_min' => 5,
            'time_limit_min' => 5,
        ]);

        $this->command?->info(sprintf(
            'Supervisor: %s / %s (PIN %s); guards: %s',
            self::SUPERVISOR_EMAIL,
            self::SUPERVISOR_PASSWORD,
            self::SUPERVISOR_PIN,
            implode(', ', array_map(fn (string $name, string $pin) => "{$name} (PIN {$pin})", array_keys(self::GUARDS), self::GUARDS)),
        ));
    }

    /**
     * A route with one version over the checkpoints, 60 seconds between neighbours.
     *
     * @param  list<Checkpoint>  $checkpoints
     */
    private function route(Site $site, User $creator, string $name, OrderMode $orderMode, array $checkpoints): Route
    {
        $route = Route::factory()->for($site)->create(['name' => $name, 'order_mode' => $orderMode]);

        $version = RouteVersion::factory()->for($route)->for($creator, 'creator')->create([
            'walk_duration_s' => 60 * (count($checkpoints) - 1),
        ]);

        foreach ($checkpoints as $index => $checkpoint) {
            RouteVersionCheckpoint::factory()->for($version)->for($checkpoint)->create([
                'position' => $index + 1,
                'walk_time_from_prev_s' => $index === 0 ? null : 60,
            ]);
        }

        $route->update(['current_version_id' => $version->id]);

        return $route;
    }
}
