<?php

declare(strict_types=1);

namespace Waypost\Auth\Policies;

use Illuminate\Auth\Access\Response;
use Waypost\Auth\Caller;
use Waypost\Models\Route;
use Waypost\Models\Schedule;

/** Schedules of the routes of the supervisor's sites. */
final class SchedulePolicy extends Policy
{
    public function viewAny(Caller $caller): bool
    {
        return true;
    }

    public function view(Caller $caller, Schedule $schedule): Response
    {
        return $this->onSite($caller, $schedule->route?->site_id);
    }

    public function create(Caller $caller, Route $route): Response
    {
        return $this->createOnSite($caller, $route->site_id);
    }

    public function update(Caller $caller, Schedule $schedule): Response
    {
        return $this->onSite($caller, $schedule->route?->site_id);
    }

    public function delete(Caller $caller, Schedule $schedule): Response
    {
        return $this->onSite($caller, $schedule->route?->site_id);
    }
}
