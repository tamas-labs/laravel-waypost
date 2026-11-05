<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers\Concerns;

use Waypost\Auth\Caller;
use Waypost\Http\Entities;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Pin\PinHasher;
use Waypost\Pin\SitePins;

/** The PIN of a user (spec: Azonosítás / PIN-kód) and api/user.response. */
trait AssignsPins
{
    /**
     * Gives the user (not saved yet) a new PIN: the given one, or a generated one. It is unique among the users of
     * the sites, because on the site phone the PIN also tells who logs in.
     *
     * Call it in the transaction that saves the user: it locks the rows of the sites until the commit, so that two
     * requests at the same time cannot give the same PIN to two users of a site. A unique index cannot do this, the PIN
     * is a salted hash.
     *
     * @param  list<string>  $siteIds
     * @return string|null the generated PIN, to be shown once; null if the PIN was given
     */
    protected static function assignPin(User $user, ?string $pin, array $siteIds): ?string
    {
        self::lockSites($siteIds);
        $pins = app(SitePins::class);
        $except = $user->exists ? $user : null;
        if ($pin !== null && $pins->isTaken($pin, $siteIds, $except)) {
            throw ApiException::conflict('Ez a PIN-kód már foglalt.', ['pin' => ['Egy telephelyen nem lehet két felhasználónak ugyanaz a PIN-kódja.']]);
        }
        $plain = $pin ?? $pins->generate($siteIds, $except);
        $user->fill(app(PinHasher::class)->hash($plain)->attributes());

        return $pin === null ? $plain : null;
    }

    /**
     * In the order of the IDs, so that two requests never wait for each other. On PostgreSQL FOR NO KEY UPDATE: it does
     * not hold up the rows that refer to the site (their foreign key check takes FOR KEY SHARE).
     *
     * @param  list<string>  $siteIds
     */
    private static function lockSites(array $siteIds): void
    {
        $postgres = Site::query()->getModel()->getConnection()->getDriverName() === 'pgsql';
        Site::withTrashed()->whereKey($siteIds)->orderBy('id')->lock($postgres ? 'for no key update' : true)->pluck('id');
    }

    /**
     * api/user.response: the user as the caller sees it, and the generated PIN if there is one.
     *
     * @return array{user: array<string, mixed>, pin?: string}
     */
    protected static function userResponse(User $user, Caller $caller, ?string $generatedPin = null): array
    {
        $user->load('sites');
        $response = ['user' => Entities::user($user, $caller)];
        if ($generatedPin !== null) {
            $response['pin'] = $generatedPin;
        }

        return $response;
    }
}
