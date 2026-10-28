<?php

declare(strict_types=1);

namespace Waypost\Auth\Policies;

use Illuminate\Auth\Access\Response;
use Waypost\Auth\Caller;
use Waypost\Models\User;

/**
 * Users who share a site with the supervisor (through the site phone: who are assigned to the phone's site), and
 * the supervisor themselves. Nobody deletes themselves, so a site never loses its last supervisor by accident.
 */
final class UserPolicy extends Policy
{
    public function viewAny(Caller $caller): bool
    {
        return true;
    }

    public function view(Caller $caller, User $user): Response
    {
        return $this->isSelf($caller, $user) || $this->sharesSite($caller, $user) ? Response::allow() : Response::denyAsNotFound();
    }

    /** The new user's sites are checked with the site policy (update). */
    public function create(Caller $caller): bool
    {
        return true;
    }

    public function update(Caller $caller, User $user): Response
    {
        return $this->view($caller, $user);
    }

    public function delete(Caller $caller, User $user): Response
    {
        return match (true) {
            $this->isSelf($caller, $user) => Response::deny('Saját magadat nem törölheted.'),
            $this->sharesSite($caller, $user) => Response::allow(),
            default => Response::denyAsNotFound(),
        };
    }

    private function isSelf(Caller $caller, User $user): bool
    {
        return $caller->user?->is($user) === true;
    }

    private function sharesSite(Caller $caller, User $user): bool
    {
        return $caller->siteIds() !== [] && $user->sites()->whereKey($caller->siteIds())->exists();
    }
}
