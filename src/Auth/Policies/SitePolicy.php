<?php

declare(strict_types=1);

namespace Waypost\Auth\Policies;

use Illuminate\Auth\Access\Response;
use Waypost\Auth\Caller;
use Waypost\Models\Site;

/** Sites: those assigned to the supervisor. A new site is created only with the supervisor's own token. */
final class SitePolicy extends Policy
{
    public function viewAny(Caller $caller): bool
    {
        return true;
    }

    public function view(Caller $caller, Site $site): Response
    {
        return $this->onSite($caller, $site->id);
    }

    public function create(Caller $caller): Response
    {
        return $caller->isActing() ? Response::deny('A telephelyi telefonról nem hozható létre új telephely.') : Response::allow();
    }

    /** Also assigning users to the site and detaching them. */
    public function update(Caller $caller, Site $site): Response
    {
        return $this->onSite($caller, $site->id);
    }

    public function delete(Caller $caller, Site $site): Response
    {
        return $this->onSite($caller, $site->id);
    }
}
