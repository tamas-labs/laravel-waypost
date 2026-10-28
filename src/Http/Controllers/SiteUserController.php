<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Waypost\Auth\Caller;
use Waypost\Http\Controllers\Concerns\AssignsPins;
use Waypost\Http\Entities;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Table;
use Waypost\Support\Time;

/** /sites/{id}/users: who is assigned to a site (site_user). */
final class SiteUserController extends Controller
{
    use AssignsPins;

    /** @return array{users: list<array<string, mixed>>} */
    public function index(Site $site, Caller $caller): array
    {
        $this->gate->authorize('view', $site);
        $users = $site->users()->with('sites')->orderBy('name')->orderBy('id')->get();

        return ['users' => array_values($users->map(fn (User $user) => Entities::user($user, $caller))->all())];
    }

    /**
     * Assigns a user whose every site the caller manages (UserPolicy::update). The user gets a new PIN, unique on all of their sites: the server only knows
     * the hash of the old one, so it cannot check that against the users of the new site. Assigning a user who is
     * already assigned changes nothing.
     *
     * @return array{user: array<string, mixed>, pin?: string}
     */
    public function store(Request $request, Site $site, Caller $caller): array
    {
        $this->gate->authorize('update', $site);
        $input = self::input($request);
        $user = User::query()->find($request->string('user_id')->toString());
        if ($user === null || $this->gate->inspect('view', $user)->denied()) {
            self::invalid('user_id', 'Nincs ilyen felhasználó.');
        }
        if ($user->sites()->whereKey($site->id)->exists()) {
            return self::userResponse($user, $caller);
        }
        // The new PIN goes to the caller: only for a user whose every site the caller manages.
        $this->gate->authorize('update', $user);

        /** @var list<string> $siteIds */
        $siteIds = [...$user->sites()->pluck('id')->all(), $site->id];
        $pin = self::assignPin($user, isset($input['pin']) && is_string($input['pin']) ? $input['pin'] : null, $siteIds);
        self::write(function () use ($user, $site): void {
            $user->sites()->attach($site->id);
            // Also when the PIN did not change: sync pull brings the new site_ids by updated_at.
            $user->touch();
        });

        return self::userResponse($user, $caller, $pin);
    }

    /**
     * Detaches the user and deletes their report subscriptions of the site. The last active supervisor of the site
     * stays, so somebody can still manage it. The detachment is recorded: sync pull lists the user as deleted for
     * the callers on the site who no longer see them.
     *
     * @return array{}
     */
    public function destroy(Site $site, User $user): array
    {
        $this->gate->authorize('update', $site);
        if (!$user->sites()->whereKey($site->id)->exists()) {
            throw ApiException::notFound();
        }
        if ($user->isLastSupervisor($site->id)) {
            throw ApiException::conflict('A telephely egyetlen aktív supervisora nem választható le, előbb rendelj hozzá egy másikat.');
        }

        DB::transaction(function () use ($site, $user): void {
            $user->sites()->detach($site->id);
            DB::table(Table::name('site_user_detachments'))->upsert(
                ['site_id' => $site->id, 'user_id' => $user->id, 'detached_at' => Time::now()->format(Time::DATABASE_FORMAT)],
                ['site_id', 'user_id'],
                ['detached_at'],
            );
            $user->reportSubscriptions()->where('site_id', $site->id)->get()->each(fn (ReportSubscription $subscription) => $subscription->delete());
            $user->touch();
        });

        return [];
    }
}
