<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Pin\PinHash;
use Waypost\Pin\PinHasher;
use Waypost\Support\Time;

use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

function pinOf(User $user, string $pin): bool
{
    $hash = PinHash::of($user->refresh());

    return $hash !== null && app(PinHasher::class)->check($pin, $hash);
}

it('lists the users of a site', function (): void {
    [$site, $other] = [Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($site);
    $guard = User::factory()->create(['name' => 'Zoltán']);
    $guard->sites()->attach($site->id);
    User::factory()->create()->sites()->attach($other->id);

    $response = callApi('GET', "sites/{$site->id}/users", $supervisor)->assertOk();

    expect($response)->toMatchSchema('api/user-list.response');
    expect($response->json('users.*.id'))->toBe([$supervisor->id, $guard->id]);
});

it('assigns a user with a new, generated PIN unique on all of their sites', function (): void {
    travelTo(Time::fromIso('2026-10-28T09:00:00.000Z'));
    [$own, $target] = [Site::factory()->create(), Site::factory()->create()];
    $guard = User::factory()->withPin('111111')->create();
    $guard->sites()->attach($own->id);
    travelTo(Time::fromIso('2026-10-28T10:00:00.000Z'));

    $response = callApi('POST', "sites/{$target->id}/users", supervisorOf($own, $target), ['user_id' => $guard->id])->assertOk();

    expect($response)->toMatchSchema('api/user.response');
    $ids = [$own->id, $target->id];
    sort($ids);
    expect($response->json('user.site_ids'))->toBe($ids);
    expect($response->json('user.updated_at'))->toBe('2026-10-28T10:00:00.000Z');
    expect(pinOf($guard, $response->json('pin')))->toBeTrue();
    expect(pinOf($guard, '111111'))->toBeFalse();
});

it('assigns a user with the given PIN, unless somebody on their sites has it', function (): void {
    [$own, $target] = [Site::factory()->create(), Site::factory()->create()];
    $guard = User::factory()->create();
    $guard->sites()->attach($own->id);
    User::factory()->withPin('222222')->create()->sites()->attach($target->id);
    $supervisor = supervisorOf($own, $target);

    callApi('POST', "sites/{$target->id}/users", $supervisor, ['user_id' => $guard->id, 'pin' => '222222'])->assertConflict()->assertJsonPath('error.code', 'conflict');
    expect($guard->sites()->count())->toBe(1);

    $response = callApi('POST', "sites/{$target->id}/users", $supervisor, ['user_id' => $guard->id, 'pin' => '333333'])->assertOk();
    expect($response->json())->not->toHaveKey('pin');
    expect(pinOf($guard, '333333'))->toBeTrue();
});

it('changes nothing for a user already assigned', function (): void {
    $site = Site::factory()->create();
    $guard = User::factory()->withPin('111111')->create();
    $guard->sites()->attach($site->id);

    $response = callApi('POST', "sites/{$site->id}/users", supervisorOf($site), ['user_id' => $guard->id])->assertOk();

    expect($response->json())->not->toHaveKey('pin');
    expect(pinOf($guard, '111111'))->toBeTrue();
});

it('assigns only users the caller sees, to sites the caller manages', function (): void {
    [$own, $foreign] = [Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($own);
    $stranger = User::factory()->create();
    $stranger->sites()->attach($foreign->id);
    $colleague = User::factory()->create();
    $colleague->sites()->attach($own->id);

    callApi('POST', "sites/{$own->id}/users", $supervisor, ['user_id' => $stranger->id])
        ->assertUnprocessable()->assertJsonPath('error.details', ['user_id' => ['Nincs ilyen felhasználó.']]);
    callApi('POST', "sites/{$foreign->id}/users", $supervisor, ['user_id' => $colleague->id])->assertNotFound();
});

it('assigns only a user whose every site the caller manages, as the new PIN goes to the caller', function (): void {
    [$own, $shared, $theirs] = [Site::factory()->create(), Site::factory()->create(), Site::factory()->create()];
    $colleague = supervisorOf($shared, $theirs);

    $response = callApi('POST', "sites/{$own->id}/users", supervisorOf($own, $shared), ['user_id' => $colleague->id])->assertForbidden();

    expect($response->json('error.message'))->toBe('A felhasználó olyan telephelyhez is tartozik, amelyet nem kezelsz.');
    expect($colleague->sites()->count())->toBe(2);
});

it('detaches a user with their subscriptions of the site', function (): void {
    [$site, $other] = [Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($site);
    $colleague = supervisorOf($site, $other);
    $here = ReportSubscription::factory()->for($site)->for($colleague)->create();
    $there = ReportSubscription::factory()->for($other)->for($colleague)->create();

    $response = callApi('DELETE', "sites/{$site->id}/users/{$colleague->id}", $supervisor)->assertOk();

    expect($response)->toMatchSchema('api/deleted.response');
    expect($colleague->sites()->pluck('id')->all())->toBe([$other->id]);
    expect($here->refresh()->trashed())->toBeTrue();
    expect($there->refresh()->trashed())->toBeFalse();
    callApi('DELETE', "sites/{$site->id}/users/{$colleague->id}", $supervisor)->assertNotFound();
});

it('lets a supervisor leave a site, but not as its last active supervisor', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $colleague = supervisorOf($site);

    callApi('DELETE', "sites/{$site->id}/users/{$colleague->id}", $supervisor)->assertOk();

    $response = callApi('DELETE', "sites/{$site->id}/users/{$supervisor->id}", $supervisor)->assertConflict();
    expect($response->json('error.message'))->toBe('A telephely egyetlen aktív supervisora nem választható le, előbb rendelj hozzá egy másikat.');
});
