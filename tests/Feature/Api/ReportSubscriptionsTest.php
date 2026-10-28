<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Site;
use Waypost\Models\User;

pest()->use(RefreshDatabase::class);

it('subscribes a supervisor of the site, and changes the frequency', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $colleague = supervisorOf($site);

    $created = callApi('POST', 'report-subscriptions', $supervisor, ['user_id' => $colleague->id, 'site_id' => $site->id, 'frequency' => 'daily'])->assertCreated();

    expect($created)->toMatchSchema('api/report-subscription.response');
    expect($created->json('report_subscription'))->toMatchArray(['user_id' => $colleague->id, 'site_id' => $site->id, 'frequency' => 'daily']);
    $id = $created->json('report_subscription.id');

    $updated = callApi('PATCH', "report-subscriptions/{$id}", $supervisor, ['frequency' => 'weekly'])->assertOk();
    expect($updated)->toMatchSchema('api/report-subscription.response');
    expect($updated->json('report_subscription.frequency'))->toBe('weekly');
    expect(callApi('GET', "report-subscriptions/{$id}", $supervisor)->assertOk()->json('report_subscription.frequency'))->toBe('weekly');
});

it('refuses the same subscription twice', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    ReportSubscription::factory()->for($site)->for($supervisor)->create(['frequency' => 'daily']);
    $weekly = ReportSubscription::factory()->for($site)->for($supervisor)->create(['frequency' => 'weekly']);

    $response = callApi('POST', 'report-subscriptions', $supervisor, ['user_id' => $supervisor->id, 'site_id' => $site->id, 'frequency' => 'daily'])->assertConflict();
    expect($response)->toMatchSchema('api/error');
    expect($response->json('error'))->toBe([
        'code' => 'conflict',
        'message' => 'Erre a jelentésre a felhasználó már feliratkozott ezen a telephelyen.',
        'details' => ['frequency' => ['Már létező feliratkozás.']],
    ]);
    callApi('PATCH', "report-subscriptions/{$weekly->id}", $supervisor, ['frequency' => 'daily'])->assertConflict();
});

it('subscribes again after the subscription was deleted', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $subscription = ReportSubscription::factory()->for($site)->for($supervisor)->create(['frequency' => 'shift']);

    expect(callApi('DELETE', "report-subscriptions/{$subscription->id}", $supervisor)->assertOk())->toMatchSchema('api/deleted.response');

    callApi('POST', 'report-subscriptions', $supervisor, ['user_id' => $supervisor->id, 'site_id' => $site->id, 'frequency' => 'shift'])->assertCreated();
});

it('subscribes only supervisors assigned to the site', function (callable $subscriber, string $message): void {
    [$site, $other] = [Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($site, $other);

    $response = callApi('POST', 'report-subscriptions', $supervisor, ['user_id' => $subscriber($site, $other)->id, 'site_id' => $site->id, 'frequency' => 'daily'])
        ->assertUnprocessable();

    expect($response->json('error.details'))->toBe(['user_id' => [$message]]);
})->with([
    'a guard' => [function (Site $site): User {
        $guard = User::factory()->create();
        $guard->sites()->attach($site->id);

        return $guard;
    }, 'Jelentést csak supervisor kaphat.'],
    'a supervisor of another site' => [fn (Site $site, Site $other): User => supervisorOf($other), 'A felhasználó nincs hozzárendelve ehhez a telephelyhez.'],
    'a user the caller does not see' => [fn (): User => supervisorOf(Site::factory()->create()), 'Nincs ilyen felhasználó.'],
]);

it('keeps subscriptions on the caller\'s sites', function (): void {
    [$own, $foreign] = [Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($own);
    $mine = ReportSubscription::factory()->for($own)->for($supervisor)->create();
    $theirs = ReportSubscription::factory()->for($foreign)->for(supervisorOf($foreign))->create();

    $list = callApi('GET', 'report-subscriptions', $supervisor)->assertOk();
    expect($list)->toMatchSchema('api/report-subscription-list.response');
    expect($list->json('report_subscriptions.*.id'))->toBe([$mine->id]);
    expect(callApi('GET', "report-subscriptions?user_id={$supervisor->id}", $supervisor)->json('report_subscriptions.*.id'))->toBe([$mine->id]);

    callApi('POST', 'report-subscriptions', $supervisor, ['user_id' => $supervisor->id, 'site_id' => $foreign->id, 'frequency' => 'daily'])->assertForbidden();
    callApi('GET', "report-subscriptions/{$theirs->id}", $supervisor)->assertNotFound();
    callApi('PATCH', "report-subscriptions/{$theirs->id}", $supervisor, ['frequency' => 'monthly'])->assertNotFound();
    callApi('DELETE', "report-subscriptions/{$theirs->id}", $supervisor)->assertNotFound();
});
