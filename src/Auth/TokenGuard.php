<?php

declare(strict_types=1);

namespace Waypost\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Waypost\Enums\Role;
use Waypost\Models\Device;
use Waypost\Models\PersonalAccessToken;
use Waypost\Models\User;
use Waypost\Support\Time;

/**
 * The "waypost" guard: the owner of the bearer token in the package's own token table (Sanctum's guard would
 * look in the host application's table). A token is valid if it has not expired and its owner exists; a user's
 * only while they are an active supervisor. A revoked device's token stays valid here: the Authenticate
 * middleware answers device_revoked, except where the device may still upload (push-final).
 *
 * The result is kept on the request, not on the guard, so a long-lived guard never serves a previous request's
 * caller.
 */
final readonly class TokenGuard implements Guard
{
    private const string ATTRIBUTE = 'waypost.token_owner';

    public function __construct(private Container $container)
    {
    }

    public function user(): User|Device|null
    {
        $request = $this->request();
        if (!$request->attributes->has(self::ATTRIBUTE)) {
            $request->attributes->set(self::ATTRIBUTE, $this->resolve($request));
        }

        $owner = $request->attributes->get(self::ATTRIBUTE);

        return $owner instanceof User || $owner instanceof Device ? $owner : null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function id(): ?string
    {
        $id = $this->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }

    /** @param  array<string, mixed>  $credentials */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return $this->check();
    }

    public function setUser(Authenticatable $user): static
    {
        $this->request()->attributes->set(self::ATTRIBUTE, $user);

        return $this;
    }

    private function request(): Request
    {
        return $this->container->make('request');
    }

    private function resolve(Request $request): User|Device|null
    {
        $plain = $request->bearerToken();
        $token = $plain === null ? null : PersonalAccessToken::findToken($plain);
        if ($token === null || ($token->expires_at !== null && $token->expires_at <= Time::now())) {
            return null;
        }

        $owner = $token->tokenable;
        $valid = match (true) {
            $owner instanceof User => $owner->active && $owner->role === Role::Supervisor,
            $owner instanceof Device => true,
            default => false,
        };

        /** @var User|Device $owner */
        return $valid ? $owner->withAccessToken($token) : null;
    }
}
