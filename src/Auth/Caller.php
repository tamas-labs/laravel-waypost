<?php

declare(strict_types=1);

namespace Waypost\Auth;

use Illuminate\Http\Request;
use LogicException;
use Waypost\Models\Device;
use Waypost\Models\PersonalAccessToken;
use Waypost\Models\User;

/**
 * Who makes an API request, as the Authenticate middleware established it:
 * - a supervisor with their own token: on every site assigned to them (site_user);
 * - the site phone with its device token: on its own site;
 * - the site phone acting for a supervisor (X-Acting-User): as that supervisor, but only on the phone's site.
 *
 * Policies take the caller instead of a user, so the acting supervisor's site restriction holds everywhere.
 */
final class Caller
{
    /** @var list<string>|null */
    private ?array $siteIds = null;

    private function __construct(
        public readonly PersonalAccessToken $token,
        public readonly ?User $user,
        public readonly ?Device $device,
    ) {
    }

    public static function supervisor(User $user, PersonalAccessToken $token): self
    {
        return new self($token, $user, null);
    }

    public static function device(Device $device, PersonalAccessToken $token): self
    {
        return new self($token, null, $device);
    }

    public static function acting(Device $device, User $supervisor, PersonalAccessToken $token): self
    {
        return new self($token, $supervisor, $device);
    }

    /** The caller of a request that passed the Authenticate middleware. */
    public static function of(Request $request): ?self
    {
        $caller = $request->attributes->get(self::class);

        return $caller instanceof self ? $caller : null;
    }

    public function attachTo(Request $request): void
    {
        $request->attributes->set(self::class, $this);
        $request->setUserResolver(fn (): User|Device => $this->owner());
    }

    /** A supervisor, with their own token or through the site phone. */
    public function isSupervisor(): bool
    {
        return $this->user !== null;
    }

    public function isActing(): bool
    {
        return $this->user !== null && $this->device !== null;
    }

    /** The token's owner: idempotency keys and rate limits belong to it. */
    public function owner(): User|Device
    {
        return $this->device ?? $this->user ?? throw new LogicException('A caller has a user or a device.');
    }

    /**
     * The sites the caller sees: the device's site, or every site of the supervisor.
     *
     * @return list<string>
     */
    public function siteIds(): array
    {
        if ($this->siteIds === null) {
            $this->siteIds = match (true) {
                $this->device !== null => $this->device->site_id === null ? [] : [$this->device->site_id],
                $this->user !== null => array_values(array_map(strval(...), $this->user->sites()->pluck('id')->all())),
                default => [],
            };
        }

        return $this->siteIds;
    }

    /** Whether the caller is a supervisor who may manage the site (and what belongs to it). */
    public function managesSite(?string $siteId): bool
    {
        return $this->isSupervisor() && $siteId !== null && in_array($siteId, $this->siteIds(), true);
    }
}
