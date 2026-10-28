<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Waypost\Http\Middleware\Authenticate;
use Waypost\Models\Device;
use Waypost\Models\PersonalAccessToken;
use Waypost\Models\Site;
use Waypost\Support\Table;
use Waypost\Support\Time;

use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

/** @return array<string, string> */
function enrolment(Site $site): array
{
    return ['site_id' => $site->id, 'name' => 'Portafülke', 'platform' => 'android', 'app_version' => '1.2.0'];
}

it('enrolls the first phone of a site', function (): void {
    travelTo(Time::fromIso('2026-10-28T09:00:00.000Z'));
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);

    $response = callApi('POST', 'devices/enroll', $supervisor, enrolment($site))->assertOk();

    expect($response)->toMatchSchema('api/devices-enroll.response');
    $device = Device::query()->sole();
    expect($response->json('device'))->toBe([
        'id' => $device->id, 'site_id' => $site->id, 'name' => 'Portafülke', 'platform' => 'android', 'app_version' => '1.2.0',
        'enrolled_by' => $supervisor->id, 'enrolled_at' => '2026-10-28T09:00:00.000Z', 'revoked_at' => null, 'last_seen_at' => null,
    ]);

    $secret = $response->json('secret');
    expect(strlen((string) base64_decode($secret, true)))->toBe(32);
    expect($device->secret)->toBe($secret);
    expect(DB::table(Table::name('devices'))->value('secret'))->not->toContain($secret);

    $token = PersonalAccessToken::findToken($response->json('token'));
    expect($token?->tokenable?->is($device))->toBeTrue();
    expect($token?->abilities)->toBe([Authenticate::DEVICE]);
});

it('works with the new token as the site phone', function (): void {
    $site = Site::factory()->create();
    $response = callApi('POST', 'devices/enroll', supervisorOf($site), enrolment($site))->assertOk();

    requestApi('GET', 'sync/pull', ['Authorization' => 'Bearer ' . $response->json('token')])->assertOk();
});

it('revokes the previous phone of the site, but keeps its token for the last upload', function (): void {
    travelTo(Time::fromIso('2026-10-28T09:00:00.000Z'));
    $site = Site::factory()->create();
    $previous = Device::factory()->for($site)->create();
    $previousToken = deviceToken($previous);
    $otherSite = Device::factory()->create();
    travelTo(Time::fromIso('2026-10-28T10:00:00.000Z'));

    callApi('POST', 'devices/enroll', supervisorOf($site), enrolment($site))->assertOk();

    expect(Time::iso($previous->refresh()->revoked_at ?? throw new LogicException('Not revoked.')))->toBe('2026-10-28T10:00:00.000Z');
    expect($otherSite->refresh()->revoked_at)->toBeNull();
    expect($previous->tokens()->count())->toBe(1);
    requestApi('GET', 'sync/pull', ['Authorization' => "Bearer {$previousToken}"])
        ->assertUnauthorized()->assertJsonPath('error.code', 'device_revoked');
});

it('gives the same phone again for a repeated request', function (): void {
    $site = Site::factory()->create();
    $headers = ['Authorization' => 'Bearer ' . supervisorToken(supervisorOf($site)), 'Idempotency-Key' => (string) Str::uuid()];

    $first = requestApi('POST', 'devices/enroll', $headers, enrolment($site))->assertOk();
    $again = requestApi('POST', 'devices/enroll', $headers, enrolment($site))->assertOk();

    expect($again->json('token'))->toBe($first->json('token'));
    expect($again->json('secret'))->toBe($first->json('secret'));
    expect(Device::query()->count())->toBe(1);
});

it('enrolls only on the supervisor\'s sites', function (): void {
    [$own, $foreign] = [Site::factory()->create(), Site::factory()->create()];

    $response = callApi('POST', 'devices/enroll', supervisorOf($own), enrolment($foreign))->assertForbidden();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.message'))->toBe('Ehhez a telephelyhez nincs jogosultságod.');
    expect(Device::query()->count())->toBe(0);
});

it('enrolls only with the supervisor\'s own token', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $phone = Device::factory()->for($site)->create();

    callApi('POST', 'devices/enroll', $supervisor, enrolment($site), $phone)->assertForbidden();
    requestApi('POST', 'devices/enroll', ['Authorization' => 'Bearer ' . deviceToken($phone)], enrolment($site))->assertForbidden();
    requestApi('POST', 'devices/enroll', [], enrolment($site))->assertUnauthorized();
    expect($phone->refresh()->revoked_at)->toBeNull();
});

it('validates the request against the schema', function (): void {
    $site = Site::factory()->create();

    $response = callApi('POST', 'devices/enroll', supervisorOf($site), [...enrolment($site), 'platform' => 'windows'])->assertUnprocessable();

    expect($response->json('error.details'))->toHaveKey('platform');
});
