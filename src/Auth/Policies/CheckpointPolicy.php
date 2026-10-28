<?php

declare(strict_types=1);

namespace Waypost\Auth\Policies;

use Illuminate\Auth\Access\Response;
use Waypost\Auth\Caller;
use Waypost\Models\Checkpoint;

/** Checkpoints of the supervisor's sites. */
final class CheckpointPolicy extends Policy
{
    public function viewAny(Caller $caller): bool
    {
        return true;
    }

    public function view(Caller $caller, Checkpoint $checkpoint): Response
    {
        return $this->onSite($caller, $checkpoint->site_id);
    }

    public function create(Caller $caller, string $siteId): Response
    {
        return $this->createOnSite($caller, $siteId);
    }

    public function update(Caller $caller, Checkpoint $checkpoint): Response
    {
        return $this->onSite($caller, $checkpoint->site_id);
    }

    public function delete(Caller $caller, Checkpoint $checkpoint): Response
    {
        return $this->onSite($caller, $checkpoint->site_id);
    }
}
