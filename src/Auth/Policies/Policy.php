<?php

declare(strict_types=1);

namespace Waypost\Auth\Policies;

use Illuminate\Auth\Access\Response;
use Waypost\Auth\Caller;

/**
 * Base of the package's policies (spec: Azonosítás / Supervisor fiók): only a supervisor configures, and only on
 * the sites assigned to them; through the site phone (X-Acting-User) only on the phone's site. A record of a site
 * the caller does not manage is not found (404), so its existence does not leak; creating something on such a
 * site is forbidden (403).
 */
abstract class Policy
{
    public function before(Caller $caller): ?Response
    {
        return $caller->isSupervisor() ? null : Response::deny('Ezt csak supervisor végezheti el.');
    }

    /** Viewing or changing a record of the site. */
    protected function onSite(Caller $caller, ?string $siteId): Response
    {
        return $caller->managesSite($siteId) ? Response::allow() : Response::denyAsNotFound();
    }

    /** Creating a record on the site. */
    protected function createOnSite(Caller $caller, string $siteId): Response
    {
        return $caller->managesSite($siteId) ? Response::allow() : Response::deny('Ehhez a telephelyhez nincs jogosultságod.');
    }
}
