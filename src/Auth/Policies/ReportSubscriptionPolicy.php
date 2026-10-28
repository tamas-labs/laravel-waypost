<?php

declare(strict_types=1);

namespace Waypost\Auth\Policies;

use Illuminate\Auth\Access\Response;
use Waypost\Auth\Caller;
use Waypost\Models\ReportSubscription;

/** Report subscriptions of the supervisor's sites, whoever the subscriber is. */
final class ReportSubscriptionPolicy extends Policy
{
    public function viewAny(Caller $caller): bool
    {
        return true;
    }

    public function view(Caller $caller, ReportSubscription $subscription): Response
    {
        return $this->onSite($caller, $subscription->site_id);
    }

    public function create(Caller $caller, string $siteId): Response
    {
        return $this->createOnSite($caller, $siteId);
    }

    public function update(Caller $caller, ReportSubscription $subscription): Response
    {
        return $this->onSite($caller, $subscription->site_id);
    }

    public function delete(Caller $caller, ReportSubscription $subscription): Response
    {
        return $this->onSite($caller, $subscription->site_id);
    }
}
