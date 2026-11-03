<?php

declare(strict_types=1);

namespace Waypost\Auth;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Gate as BaseGate;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Container\Container;

/**
 * The package's own gate: its policies take the request's Caller instead of a user. Separate from the host
 * application's gate, so the host's before-callbacks (typed for its own users) never see a Caller, and the
 * package's policies never decide about the host's models.
 *
 * It wraps a Laravel gate instead of being one: the container hands every resolved object that is a Gate
 * (instanceof the contract) to the host's afterResolving callbacks, and spatie/laravel-permission adds its
 * before-callback, typed for Authorizable users, there. The wrapped gate is built here and never resolved.
 */
final class Gate
{
    private GateContract $gate;

    /**
     * @param  callable(): ?Caller  $callerResolver
     * @param  array<class-string, class-string>  $policies
     */
    public function __construct(Container $container, callable $callerResolver, array $policies)
    {
        $this->gate = new BaseGate($container, $callerResolver, policies: $policies);
    }

    /** The same policies for the given caller. */
    public function forUser(Caller $caller): self
    {
        $gate = clone $this;
        $gate->gate = $this->gate->forUser($caller);

        return $gate;
    }

    /**
     * @param  mixed[]|mixed  $arguments
     *
     * @throws AuthorizationException
     */
    public function authorize(string $ability, mixed $arguments = []): Response
    {
        return $this->gate->authorize($ability, $arguments);
    }

    /** @param  mixed[]|mixed  $arguments */
    public function inspect(string $ability, mixed $arguments = []): Response
    {
        return $this->gate->inspect($ability, $arguments);
    }

    public function getPolicyFor(object|string $class): mixed
    {
        return $this->gate->getPolicyFor($class);
    }
}
