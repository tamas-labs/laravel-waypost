<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Waypost\Auth\Caller;
use Waypost\Enums\Role;
use Waypost\Http\Controllers\Concerns\AssignsPins;
use Waypost\Http\Entities;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Models\ReportSubscription;
use Waypost\Models\User;

/** /users (spec: Azonosítás / Supervisor fiók, PIN-kód): guards and supervisors of the caller's sites. */
final class UserController extends Controller
{
    use AssignsPins;

    public const string EMAIL_TAKEN = 'Ezzel az e-mail-címmel már van felhasználó.';

    /**
     * The users who share a site with the caller, and the caller; ?site_id= only those of that site.
     *
     * @return array{users: list<array<string, mixed>>}
     */
    public function index(Request $request, Caller $caller): array
    {
        $siteId = self::uuidParameter($request, 'site_id');
        $query = User::query()->with('sites')->orderBy('name')->orderBy('id');
        if ($siteId !== null) {
            $query->whereHas('sites', fn (Builder $sites) => $sites->whereKey(in_array($siteId, $caller->siteIds(), true) ? [$siteId] : []));
        } else {
            $query->where(function (Builder $users) use ($caller): void {
                $users->whereHas('sites', fn (Builder $sites) => $sites->whereKey($caller->siteIds()));
                if ($caller->user !== null) {
                    $users->orWhereKey($caller->user->id);
                }
            });
        }

        return ['users' => array_values($query->get()->map(fn (User $user) => Entities::user($user, $caller))->all())];
    }

    public function store(Request $request, Caller $caller): JsonResponse
    {
        $input = self::input($request);
        /** @var list<string> $siteIds */
        $siteIds = $input['site_ids'];
        $this->gate->authorize('create', [User::class, $siteIds]);
        self::checkEmail($input['email'] ?? null);

        $user = new User([...Arr::except($input, ['site_ids', 'pin']), 'id' => self::newId($request, User::class)]);
        $pin = self::assignPin($user, self::pinOf($input), $siteIds);
        self::write(function () use ($user, $siteIds): void {
            $user->save();
            $user->sites()->attach($siteIds);
        }, self::EMAIL_TAKEN);

        return self::created(self::userResponse($user, $caller, $pin));
    }

    /** @return array{user: array<string, mixed>} */
    public function show(User $user, Caller $caller): array
    {
        $this->gate->authorize('view', $user);

        return self::userResponse($user, $caller);
    }

    /**
     * active: false deactivates the user; their tokens stop working. Nobody deactivates themselves (the policy lets
     * only a supervisor of every site of the user change them, so no site loses its last active supervisor).
     *
     * @return array{user: array<string, mixed>}
     */
    public function update(Request $request, User $user, Caller $caller): array
    {
        $this->gate->authorize('update', $user);
        $input = self::input($request);
        if ($user->role === Role::Guard) {
            foreach (['email' => 'Őrnek nincs e-mail-címe.', 'password' => 'Őrnek nincs jelszava.'] as $field => $message) {
                if (array_key_exists($field, $input)) {
                    self::invalid($field, $message);
                }
            }
        }
        self::checkEmail($input['email'] ?? null, $user);
        if (($input['active'] ?? true) === false && $caller->user?->is($user) === true) {
            throw ApiException::forbidden('Saját magadat nem inaktiválhatod.');
        }

        self::write(fn () => $user->update($input), self::EMAIL_TAKEN);

        return self::userResponse($user, $caller);
    }

    /**
     * PUT /users/{id}/pin: a new PIN, unique on all of the user's sites.
     *
     * @return array{user: array<string, mixed>, pin?: string}
     */
    public function pin(Request $request, User $user, Caller $caller): array
    {
        $this->gate->authorize('update', $user);
        /** @var list<string> $siteIds */
        $siteIds = $user->sites()->pluck('id')->all();

        $pin = self::assignPin($user, self::pinOf(self::input($request)), $siteIds);
        $user->save();

        return self::userResponse($user, $caller, $pin);
    }

    /**
     * Also deletes the user's report subscriptions and tokens.
     *
     * @return array{}
     */
    public function destroy(User $user): array
    {
        $this->gate->authorize('delete', $user);

        DB::transaction(function () use ($user): void {
            $user->reportSubscriptions()->get()->each(fn (ReportSubscription $subscription) => $subscription->delete());
            $user->tokens()->delete();
            $user->delete();
        });

        return [];
    }

    /** @param  array<string, mixed>  $input */
    private static function pinOf(array $input): ?string
    {
        return isset($input['pin']) && is_string($input['pin']) ? $input['pin'] : null;
    }

    private static function checkEmail(mixed $email, ?User $except = null): void
    {
        if (!is_string($email)) {
            return;
        }
        $taken = User::query()->where('email', Str::lower(trim($email)))->when($except, fn (Builder $users, User $user) => $users->whereKeyNot($user->id))->exists();
        if ($taken) {
            throw ApiException::conflict(self::EMAIL_TAKEN, ['email' => ['Foglalt e-mail-cím.']]);
        }
    }
}
