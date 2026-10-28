<?php

declare(strict_types=1);

namespace Waypost\Auth\Policies;

use Illuminate\Auth\Access\Response;
use Waypost\Auth\Caller;
use Waypost\Models\Route;

/** Routes of the supervisor's sites, and their versions (creating a version is an update of the route). */
final class RoutePolicy extends Policy
{
    public function viewAny(Caller $caller): bool
    {
        return true;
    }

    public function view(Caller $caller, Route $route): Response
    {
        return $this->onSite($caller, $route->site_id);
    }

    public function create(Caller $caller, string $siteId): Response
    {
        return $this->createOnSite($caller, $siteId);
    }

    public function update(Caller $caller, Route $route): Response
    {
        return $this->onSite($caller, $route->site_id);
    }

    public function delete(Caller $caller, Route $route): Response
    {
        return $this->onSite($caller, $route->site_id);
    }
}
