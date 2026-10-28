<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers;

use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Waypost\Auth\Caller;
use Waypost\Http\Entities;
use Waypost\Models\Checkpoint;
use Waypost\Models\Device;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Route;
use Waypost\Models\Schedule;
use Waypost\Models\Site;
use Waypost\Support\Time;

/** /sites (spec: API-szerződés): the sites of the caller. */
final class SiteController extends Controller
{
    /** @return array{sites: list<array<string, mixed>>} */
    public function index(Caller $caller): array
    {
        $sites = Site::query()->whereKey($caller->siteIds())->orderBy('name')->orderBy('id')->get();

        return ['sites' => array_values($sites->map(Entities::site(...))->all())];
    }

    /** The creator is assigned to the new site, so they see it. */
    public function store(Request $request, Caller $caller): JsonResponse
    {
        $this->gate->authorize('create', Site::class);
        $input = self::input($request);
        self::checkTimezone($input);
        $supervisor = $caller->user ?? throw new LogicException('Only a supervisor creates a site.');

        $site = new Site([...$input, 'id' => self::newId($request, Site::class), 'created_by' => $supervisor->id]);
        self::write(function () use ($site, $supervisor): void {
            $site->save();
            $site->users()->attach($supervisor->id);
            $supervisor->touch();
        });

        return self::created(['site' => Entities::site($site)]);
    }

    /** @return array{site: array<string, mixed>} */
    public function show(Site $site): array
    {
        $this->gate->authorize('view', $site);

        return ['site' => Entities::site($site)];
    }

    /** @return array{site: array<string, mixed>} */
    public function update(Request $request, Site $site): array
    {
        $this->gate->authorize('update', $site);
        $input = self::input($request);
        self::checkTimezone($input);

        $site->update($input);

        return ['site' => Entities::site($site)];
    }

    /**
     * Deletes what belongs to the site with it (sync pull lists all of it as deleted) and revokes its phone: the
     * phone may still upload its last entries (push-final).
     *
     * @return array{}
     */
    public function destroy(Site $site): array
    {
        $this->gate->authorize('delete', $site);

        DB::transaction(function () use ($site): void {
            $site->routes()->with('schedules')->get()->each(function (Route $route): void {
                $route->schedules->each(fn (Schedule $schedule) => $schedule->delete());
                $route->delete();
            });
            $site->checkpoints()->get()->each(fn (Checkpoint $checkpoint) => $checkpoint->delete());
            $site->reportSubscriptions()->get()->each(fn (ReportSubscription $subscription) => $subscription->delete());
            $site->devices()->whereNull('revoked_at')->get()->each(fn (Device $device) => $device->update(['revoked_at' => Time::now()]));
            $site->delete();
        });

        return [];
    }

    /** @param  array<string, mixed>  $input */
    private static function checkTimezone(array $input): void
    {
        if (isset($input['timezone']) && !in_array($input['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            self::invalid('timezone', 'Ismeretlen időzóna.');
        }
    }
}
