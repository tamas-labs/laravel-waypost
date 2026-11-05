<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Waypost\Models\ChainEntry;
use Waypost\Models\Device;
use Waypost\Models\Incident;
use Waypost\Models\Shift;
use Waypost\Support\Uuid;
use Waypost\Tests\Fixtures\ChainBuilder;

pest()->use(RefreshDatabase::class);

it('takes the entries of a revoked device in batches, checked as a push', function (): void {
    $device = Device::factory()->revoked()->create();
    $chain = new ChainBuilder($device);
    $shift = $chain->shiftStarted();
    $incident = $chain->incident($shift['payload']['shift_id']);
    $closing = $chain->shiftClosed($shift['payload']['shift_id']);

    $first = pushAs($device, [$shift, $incident], done: false)->assertOk();
    expect($first)->toMatchSchema('api/sync-push-final.response');
    expect($first->json())->toMatchArray(['accepted' => [$shift['id'], $incident['id']], 'last_seq' => 2, 'rejected' => []]);

    // Not closed by the batches: the next one is still taken.
    $second = pushAs($device, [$closing], done: false)->assertOk();
    expect($second->json('accepted'))->toBe([$closing['id']]);
    expect($device->refresh()->upload_closed_at)->toBeNull()
        ->and(Incident::query()->count())->toBe(1)
        ->and(Shift::query()->sole()->ended_at)->not->toBeNull();
});

it('checks gaps, forks and HMACs as a push does', function (): void {
    $device = Device::factory()->revoked()->create();
    $chain = new ChainBuilder($device);
    $shift = $chain->shiftStarted();
    $second = $chain->incident($shift['payload']['shift_id']);
    $third = $chain->incident($shift['payload']['shift_id']);

    $response = pushAs($device, [['hash' => str_repeat('b', 64)] + $shift, $third], done: false)->assertOk();

    expect($response->json('rejected'))->toBe([
        ['id' => $shift['id'], 'code' => 'bad_hmac', 'expected_seq' => null],
        ['id' => $third['id'], 'code' => 'chain_gap', 'expected_seq' => 1],
    ]);
    expect(pushAs($device, [$shift, $second], done: false)->assertOk()->json('accepted'))->toBe([$shift['id'], $second['id'], $third['id']]);
    pushAs($device, [$third, $shift], done: false)->assertUnprocessable();
});

it('closes with done: true, even with an empty list, and answers device_revoked after that', function (): void {
    $device = Device::factory()->revoked()->create();

    $response = pushAs($device, [], done: true)->assertOk();

    expect($response)->toMatchSchema('api/sync-push-final.response');
    expect($response->json())->toMatchArray(['accepted' => [], 'last_seq' => 0, 'rejected' => [], 'media_missing' => []]);
    expect($device->refresh()->upload_closed_at)->not->toBeNull();

    $closed = pushAs($device, [], done: true)->assertUnauthorized();
    expect($closed)->toMatchSchema('api/error');
    expect($closed->json('error.code'))->toBe('device_revoked');
});

it('takes the entries of the request that closes', function (): void {
    $device = Device::factory()->revoked()->create();
    $chain = new ChainBuilder($device);
    $shift = $chain->shiftStarted();

    expect(pushAs($device, [$shift], done: true)->assertOk()->json('accepted'))->toBe([$shift['id']]);
    expect(ChainEntry::query()->count())->toBe(1)->and($device->refresh()->upload_closed_at)->not->toBeNull();
    pushAs($device, [$chain->incident($shift['payload']['shift_id'])], done: false)->assertUnauthorized();
});

it('closes even if entries were rejected', function (): void {
    $device = Device::factory()->revoked()->create();
    $chain = new ChainBuilder($device);
    $chain->seq = 5;

    expect(pushAs($device, [$chain->shiftStarted()], done: true)->assertOk()->json('rejected.0.code'))->toBe('chain_gap');
    pushAs($device, [], done: true)->assertUnauthorized();
});

it('is forbidden for a device that is not revoked', function (bool $done): void {
    $device = Device::factory()->create();
    // An empty batch only closes the upload (api/sync-push-final.request): a batch before that has an entry.
    $entries = $done ? [] : [(new ChainBuilder($device))->shiftStarted()];

    $response = pushAs($device, $entries, done: $done)->assertForbidden();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.code'))->toBe('forbidden');
    expect($device->refresh()->upload_closed_at)->toBeNull()
        ->and(ChainEntry::query()->count())->toBe(0);
})->with(['closing' => true, 'a batch' => false]);

it('refuses X-Acting-User with the token of a revoked device', function (): void {
    $device = Device::factory()->revoked()->create();

    requestApi('POST', 'sync/push-final', ['Authorization' => 'Bearer ' . deviceToken($device), 'X-Acting-User' => Uuid::v7()], ['device_id' => $device->id, 'entries' => [], 'done' => true])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'device_revoked');
});

it('keeps a revoked device out of every other endpoint', function (): void {
    $device = Device::factory()->revoked()->create();

    pullAs($device)->assertUnauthorized()->assertJsonPath('error.code', 'device_revoked');
    pushAs($device, [])->assertUnauthorized()->assertJsonPath('error.code', 'device_revoked');
});

it('refuses a device ID other than the token\'s', function (): void {
    $device = Device::factory()->revoked()->create();

    requestApi('POST', 'sync/push-final', ['Authorization' => 'Bearer ' . deviceToken($device)], ['device_id' => Uuid::v7(), 'entries' => [], 'done' => true])
        ->assertUnprocessable();
    expect($device->refresh()->upload_closed_at)->toBeNull();
});
