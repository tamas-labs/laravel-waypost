<?php

declare(strict_types=1);

namespace Waypost\Auth\Policies;

use Illuminate\Auth\Access\Response;
use Waypost\Auth\Caller;

/** Site phones: a supervisor enrolls one on their own sites. */
final class DevicePolicy extends Policy
{
    public function create(Caller $caller, string $siteId): Response
    {
        return $this->createOnSite($caller, $siteId);
    }
}
