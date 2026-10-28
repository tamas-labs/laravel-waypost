<?php

declare(strict_types=1);

namespace Waypost\Auth\Policies;

use Illuminate\Auth\Access\Response;
use Waypost\Auth\Caller;
use Waypost\Models\User;

/**
 * A supervisor sees the users who share a site with them (through the site phone: who are assigned to the phone's
 * site), and themselves. Changing or deleting another user needs every site of that user: otherwise a supervisor
 * of one shared site could take over a colleague's account (e-mail, password, PIN) and with it the colleague's
 * other sites. Nobody deletes themselves; as the caller manages every site of the user, no site loses its last
 * supervisor this way.
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

    /**
     * A new user on the given sites: each of them the caller's.
     *
     * @param  list<string>  $siteIds
     */
    public function create(Caller $caller, array $siteIds): Response
    {
        foreach ($siteIds as $siteId) {
            $response = $this->createOnSite($caller, $siteId);
            if ($response->denied()) {
                return $response;
            }
        }

        return Response::allow();
    }

    /** Also the PIN, and assigning the user to another site (which gives them a new PIN). */
    public function update(Caller $caller, User $user): Response
    {
        return $this->isSelf($caller, $user) ? Response::allow() : $this->manage($caller, $user);
    }

    public function delete(Caller $caller, User $user): Response
    {
        return $this->isSelf($caller, $user) ? Response::deny('Saját magadat nem törölheted.') : $this->manage($caller, $user);
    }

    private function manage(Caller $caller, User $user): Response
    {
        return match (true) {
            !$this->sharesSite($caller, $user) => Response::denyAsNotFound(),
            $user->sites()->whereKeyNot($caller->siteIds())->exists() => Response::deny('A felhasználó olyan telephelyhez is tartozik, amelyet nem kezelsz.'),
            default => Response::allow(),
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
