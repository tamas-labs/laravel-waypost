<?php

declare(strict_types=1);

namespace Waypost\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Waypost\Auth\Caller;
use Waypost\Enums\Role;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Models\Device;
use Waypost\Models\PersonalAccessToken;
use Waypost\Models\User;
use Waypost\Support\Uuid;

/**
 * Authenticates an API request with the "waypost" guard and attaches its Caller. The parameters are the callers
 * the route accepts (Authenticate::using()):
 * - "supervisor": a supervisor token (supervisor ability);
 * - "device": a device token (device ability), without X-Acting-User;
 * - "acting": a device token with X-Acting-User, the ID of a supervisor assigned to the device's site.
 *
 * No valid token: unauthenticated (401). A revoked device: device_revoked (401), whatever the route. A token the
 * route does not accept, or an X-Acting-User the route or the token does not allow: forbidden (403).
 */
final readonly class Authenticate implements AuthenticatesRequests
{
    public const string SUPERVISOR = 'supervisor';

    public const string DEVICE = 'device';

    public const string ACTING = 'acting';

    public const string ACTING_USER_HEADER = 'X-Acting-User';

    public function __construct(private AuthFactory $auth)
    {
    }

    /** The middleware with the accepted callers, e.g. Authenticate::using(Authenticate::SUPERVISOR, Authenticate::ACTING). */
    public static function using(string ...$callers): string
    {
        return self::class . ':' . implode(',', $callers);
    }

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string ...$callers): Response
    {
        $owner = $this->auth->guard('waypost')->user();
        $token = $owner instanceof User || $owner instanceof Device ? $owner->currentAccessToken() : null;
        if (!$token instanceof PersonalAccessToken) {
            throw new AuthenticationException('Unauthenticated.', ['waypost']);
        }

        $caller = $owner instanceof Device
            ? $this->deviceCaller($request, $owner, $token, array_values($callers))
            : $this->supervisorCaller($request, $owner, $token, array_values($callers));
        $caller->attachTo($request);

        return $next($request);
    }

    /** @param  list<string>  $callers */
    private function supervisorCaller(Request $request, User $user, PersonalAccessToken $token, array $callers): Caller
    {
        if ($request->headers->has(self::ACTING_USER_HEADER)) {
            throw ApiException::forbidden('Az X-Acting-User fejléc csak a telephelyi telefon tokenjével küldhető.');
        }
        if (!in_array(self::SUPERVISOR, $callers, true) || !$token->can(self::SUPERVISOR)) {
            throw ApiException::forbidden('Ezt a műveletet supervisor fiókkal nem lehet elvégezni.');
        }

        return Caller::supervisor($user, $token);
    }

    /** @param  list<string>  $callers */
    private function deviceCaller(Request $request, Device $device, PersonalAccessToken $token, array $callers): Caller
    {
        if ($device->revoked_at !== null) {
            throw ApiException::deviceRevoked();
        }
        if (!$token->can(self::DEVICE)) {
            throw ApiException::forbidden();
        }

        $actingId = $request->headers->get(self::ACTING_USER_HEADER);
        if ($actingId === null) {
            if (!in_array(self::DEVICE, $callers, true)) {
                throw ApiException::forbidden(in_array(self::ACTING, $callers, true)
                    ? 'A telephelyi telefonról ehhez egy supervisor PIN-kódos belépése kell (X-Acting-User).'
                    : 'Ezt a műveletet a telephelyi telefonról nem lehet elvégezni.');
            }

            return Caller::device($device, $token);
        }

        if (!in_array(self::ACTING, $callers, true)) {
            throw ApiException::forbidden('Ez a művelet nem végezhető el X-Acting-User fejléccel.');
        }

        return Caller::acting($device, $this->actingSupervisor($device, $actingId), $token);
    }

    /** The supervisor of X-Acting-User: active and assigned to the device's site. */
    private function actingSupervisor(Device $device, string $id): User
    {
        $supervisor = Uuid::isV7($id) && $device->site_id !== null
            ? User::query()
                ->whereKey($id)
                ->where('role', Role::Supervisor)
                ->where('active', true)
                ->whereHas('sites', fn ($sites) => $sites->whereKey($device->site_id))
                ->first()
            : null;

        return $supervisor ?? throw ApiException::forbidden('Az X-Acting-User fejlécben megadott supervisor nem jogosult ezen a telephelyen.');
    }
}
