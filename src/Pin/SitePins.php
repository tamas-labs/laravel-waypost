<?php

declare(strict_types=1);

namespace Waypost\Pin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Waypost\Models\User;

/**
 * PIN uniqueness within a site (spec: PIN-kód): the PIN identifies the user on the site phone, so no two users of
 * a site may share one. Only hashes are stored, so the candidate is hashed again with the salt and iteration count
 * of every user of the sites who has a PIN. Inactive users count (they can be reactivated), deleted ones do not.
 */
final readonly class SitePins
{
    /** Attempts of generate() before it gives up (with a few dozen users per site, practically never). */
    public const int MAX_ATTEMPTS = 100;

    public function __construct(private PinHasher $hasher)
    {
    }

    /**
     * Whether a user of any of the sites, other than $except, already has this PIN.
     *
     * @param  list<string>  $siteIds
     */
    public function isTaken(string $pin, array $siteIds, ?User $except = null): bool
    {
        return $this->takenIn($pin, $this->holders($siteIds, $except));
    }

    /**
     * A random PIN that no user of the sites, other than $except, has.
     *
     * @param  list<string>  $siteIds
     *
     * @throws RuntimeException if no free PIN was found in MAX_ATTEMPTS attempts
     */
    public function generate(array $siteIds, ?User $except = null): string
    {
        $holders = $this->holders($siteIds, $except);
        $rejected = [];

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $pin = $this->hasher->generate();
            if (isset($rejected[$pin])) {
                continue;
            }
            if (!$this->takenIn($pin, $holders)) {
                return $pin;
            }
            $rejected[$pin] = true;
        }

        throw new RuntimeException('No free PIN found in ' . self::MAX_ATTEMPTS . ' attempts.');
    }

    /** @param  Collection<int, User>  $holders */
    private function takenIn(string $pin, Collection $holders): bool
    {
        return $holders->contains(function (User $user) use ($pin): bool {
            $hash = PinHash::of($user);

            return $hash !== null && $this->hasher->check($pin, $hash);
        });
    }

    /**
     * Users of the sites who have a PIN.
     *
     * @param  list<string>  $siteIds
     * @return Collection<int, User>
     */
    private function holders(array $siteIds, ?User $except): Collection
    {
        if ($siteIds === []) {
            return new Collection();
        }

        return User::query()
            ->whereNotNull('pin_hash')
            ->whereHas('sites', fn (Builder $sites) => $sites->whereKey($siteIds))
            ->when($except, fn (Builder $users, User $user) => $users->whereKeyNot($user->getKey()))
            ->get(['id', 'pin_hash', 'pin_salt', 'pin_iterations']);
    }
}
