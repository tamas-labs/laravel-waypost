<?php

declare(strict_types=1);

namespace Waypost\Auth;

use Illuminate\Auth\Access\Gate as BaseGate;

/**
 * The package's own gate: its policies take the request's Caller instead of a user. Separate from the host
 * application's gate, so the host's before-callbacks (typed for its own users) never see a Caller, and the
 * package's policies never decide about the host's models.
 */
final class Gate extends BaseGate
{
}
