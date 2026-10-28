<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Waypost\Auth\Caller;
use Waypost\Enums\Role;
use Waypost\Http\Entities;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Models\ReportSubscription;
use Waypost\Models\User;

/**
 * /report-subscriptions (spec: Jelentések): who gets the e-mail reports of the caller's sites. Only a supervisor
 * assigned to the site (guards have no e-mail address); one per (user, site, frequency).
 */
final class ReportSubscriptionController extends Controller
{
    private const string TAKEN = 'Erre a jelentésre a felhasználó már feliratkozott ezen a telephelyen.';

    /** @return array{report_subscriptions: list<array<string, mixed>>} */
    public function index(Request $request, Caller $caller): array
    {
        $userId = self::uuidParameter($request, 'user_id');
        $subscriptions = ReportSubscription::query()
            ->whereIn('site_id', self::visibleSites($request, $caller))
            ->when($userId, fn ($subscriptions, string $id) => $subscriptions->where('user_id', $id))
            ->orderBy('id')
            ->get();

        return ['report_subscriptions' => array_values($subscriptions->map(Entities::reportSubscription(...))->all())];
    }

    public function store(Request $request): JsonResponse
    {
        $input = self::input($request);
        $this->gate->authorize('create', [ReportSubscription::class, $input['site_id']]);
        $user = User::query()->find($request->string('user_id')->toString());
        if ($user === null || $this->gate->inspect('view', $user)->denied()) {
            self::invalid('user_id', 'Nincs ilyen felhasználó.');
        }
        if ($user->role !== Role::Supervisor) {
            self::invalid('user_id', 'Jelentést csak supervisor kaphat.');
        }
        if (!$user->sites()->whereKey($input['site_id'])->exists()) {
            self::invalid('user_id', 'A felhasználó nincs hozzárendelve ehhez a telephelyhez.');
        }

        $subscription = new ReportSubscription([...$input, 'id' => self::newId($request, ReportSubscription::class)]);
        self::save($subscription);

        return self::created(['report_subscription' => Entities::reportSubscription($subscription)]);
    }

    /** @return array{report_subscription: array<string, mixed>} */
    public function show(ReportSubscription $subscription): array
    {
        $this->gate->authorize('view', $subscription);

        return ['report_subscription' => Entities::reportSubscription($subscription)];
    }

    /** @return array{report_subscription: array<string, mixed>} */
    public function update(Request $request, ReportSubscription $subscription): array
    {
        $this->gate->authorize('update', $subscription);

        $subscription->fill(self::input($request));
        self::save($subscription);

        return ['report_subscription' => Entities::reportSubscription($subscription)];
    }

    /** @return array{} */
    public function destroy(ReportSubscription $subscription): array
    {
        $this->gate->authorize('delete', $subscription);

        $subscription->delete();

        return [];
    }

    private static function save(ReportSubscription $subscription): void
    {
        $taken = ReportSubscription::query()
            ->whereKeyNot($subscription->id)
            ->where('user_id', $subscription->user_id)
            ->where('site_id', $subscription->site_id)
            ->where('frequency', $subscription->frequency)
            ->exists();
        if ($taken) {
            throw ApiException::conflict(self::TAKEN, ['frequency' => ['Már létező feliratkozás.']]);
        }

        self::write(fn () => $subscription->save(), self::TAKEN);
    }
}
