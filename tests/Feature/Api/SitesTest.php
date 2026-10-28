<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Waypost\Models\Checkpoint;
use Waypost\Models\Device;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Route;
use Waypost\Models\Schedule;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Time;
use Waypost\Support\Uuid;

use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

it('lists the sites of the supervisor only', function (): void {
    [$a, $b] = [Site::factory()->create(['name' => 'B telephely']), Site::factory()->create(['name' => 'A telephely'])];
    Site::factory()->create();
    $supervisor = supervisorOf($a, $b);

    $response = callApi('GET', 'sites', $supervisor)->assertOk();

    expect($response)->toMatchSchema('api/site-list.response');
    expect($response->json('sites.*.id'))->toBe([$b->id, $a->id]);
});

it('lists only the site of the phone for a supervisor acting through it', function (): void {
    [$own, $other] = [Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($own, $other);

    $response = callApi('GET', 'sites', $supervisor, phone: Device::factory()->for($own)->create())->assertOk();

    expect($response->json('sites.*.id'))->toBe([$own->id]);
});

it('creates a site with the defaults and assigns the creator', function (): void {
    travelTo(Time::fromIso('2026-10-28T09:00:00.000Z'));
    $supervisor = User::factory()->supervisor()->create();

    $response = callApi('POST', 'sites', $supervisor, ['name' => 'Raktár B'])->assertCreated();

    expect($response)->toMatchSchema('api/site.response');
    expect($response->json('site'))->toMatchArray([
        'name' => 'Raktár B',
        'address' => null,
        'timezone' => 'Europe/Budapest',
        'no_data_alert_after_min' => 120,
        'created_by' => $supervisor->id,
        'created_at' => '2026-10-28T09:00:00.000Z',
    ]);
    expect(Uuid::isV7($response->json('site.id')))->toBeTrue();
    expect($supervisor->sites()->pluck('id')->all())->toBe([$response->json('site.id')]);
    callApi('GET', 'sites/' . $response->json('site.id'), $supervisor)->assertOk();
});

it('keeps the id the app generated, and refuses a taken one', function (): void {
    $supervisor = User::factory()->supervisor()->create();
    $id = Uuid::v7();

    callApi('POST', 'sites', $supervisor, ['id' => $id, 'name' => 'Raktár B', 'timezone' => 'UTC'])->assertCreated()->assertJsonPath('site.id', $id);

    $response = callApi('POST', 'sites', $supervisor, ['id' => $id, 'name' => 'Raktár C'])->assertConflict();
    expect($response)->toMatchSchema('api/error');
    expect($response->json('error'))->toBe([
        'code' => 'conflict',
        'message' => 'Ezzel az azonosítóval már létezik rekord.',
        'details' => ['id' => ['Foglalt azonosító.']],
    ]);
});

it('refuses an unknown time zone', function (string $method, string $path): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);

    $response = callApi($method, str_replace('{site}', $site->id, $path), $supervisor, ['name' => 'Raktár B', 'timezone' => 'Europe/Atlantis'])->assertUnprocessable();

    expect($response->json('error.details'))->toBe(['timezone' => ['Ismeretlen időzóna.']]);
})->with([
    'create' => ['POST', 'sites'],
    'update' => ['PATCH', 'sites/{site}'],
]);

it('does not create a site through the site phone', function (): void {
    $site = Site::factory()->create();

    $response = callApi('POST', 'sites', supervisorOf($site), ['name' => 'Raktár B'], Device::factory()->for($site)->create())->assertForbidden();

    expect($response->json('error.message'))->toBe('A telephelyi telefonról nem hozható létre új telephely.');
});

it('updates a site', function (): void {
    $site = Site::factory()->create(['address' => 'Budapest, Fő utca 1.']);

    $response = callApi('PATCH', "sites/{$site->id}", supervisorOf($site), ['name' => 'Raktár C', 'address' => null, 'no_data_alert_after_min' => 60])->assertOk();

    expect($response)->toMatchSchema('api/site.response');
    expect($response->json('site'))->toMatchArray(['name' => 'Raktár C', 'address' => null, 'no_data_alert_after_min' => 60]);
    expect($site->refresh()->address)->toBeNull();
});

it('answers not_found for a site of somebody else, also through the site phone', function (string $method): void {
    [$own, $foreign] = [Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($own, $foreign);
    $phone = Device::factory()->for($own)->create();

    callApi($method, "sites/{$foreign->id}", supervisorOf($own), ['name' => 'X'])->assertNotFound()->assertJsonPath('error.code', 'not_found');
    callApi($method, "sites/{$foreign->id}", $supervisor, ['name' => 'X'], $phone)->assertNotFound();
})->with(['GET', 'PATCH', 'DELETE']);

it('refuses the site phone without a supervisor', function (): void {
    $site = Site::factory()->create();

    $headers = ['Authorization' => 'Bearer ' . deviceToken(Device::factory()->for($site)->create()), 'Idempotency-Key' => (string) Str::uuid()];

    postJson('api/waypost/v1/sites', ['name' => 'X'], $headers)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
});

it('deletes a site with what belongs to it, and revokes its phone', function (): void {
    travelTo(Time::fromIso('2026-10-28T09:00:00.000Z'));
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $route = Route::factory()->for($site)->create();
    $schedule = Schedule::factory()->for($route)->create();
    $checkpoint = Checkpoint::factory()->for($site)->create();
    $subscription = ReportSubscription::factory()->for($site)->for($supervisor)->create();
    $phone = Device::factory()->for($site)->create();
    $other = Checkpoint::factory()->create();

    $response = callApi('DELETE', "sites/{$site->id}", $supervisor)->assertOk();

    expect($response)->toMatchSchema('api/deleted.response');
    foreach ([$site, $route, $schedule, $checkpoint, $subscription] as $model) {
        expect($model->refresh()->trashed())->toBeTrue($model::class);
    }
    expect($phone->refresh()->revoked_at)->toEqual(Time::fromIso('2026-10-28T09:00:00.000Z'));
    expect($other->refresh()->trashed())->toBeFalse();
    callApi('GET', "sites/{$site->id}", $supervisor)->assertNotFound();
    callApi('GET', "checkpoints/{$checkpoint->id}", $supervisor)->assertNotFound();
});

it('has no endpoint for a path parameter that is not a UUID', function (): void {
    $response = callApi('GET', 'sites/1', User::factory()->supervisor()->create())->assertNotFound();

    expect($response->json('error.message'))->toBe('Nincs ilyen végpont.');
});
