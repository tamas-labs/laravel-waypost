<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Waypost\Chain\ChainProcessor;
use Waypost\Chain\EntryHash;
use Waypost\Chain\UnreadableDeviceSecret;
use Waypost\Enums\VerifyStatus;
use Waypost\Models\ChainEntry;
use Waypost\Models\Device;
use Waypost\Models\Incident;
use Waypost\Models\Media;
use Waypost\Models\Shift;
use Waypost\Support\Table;
use Waypost\Support\Time;
use Waypost\Support\Uuid;
use Waypost\Tests\Fixtures\ChainBuilder;

use function Pest\Laravel\call;
use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

/**
 * A shift_started and incidents: entries with projections, seq 1 … $count.
 *
 * @return array{ChainBuilder, list<array<string, mixed>>}
 */
function chainOf(Device $device, int $count): array
{
    $chain = new ChainBuilder($device);
    $first = $chain->shiftStarted();
    $entries = [$first];
    for ($seq = 2; $seq <= $count; $seq++) {
        $entries[] = $chain->incident($first['payload']['shift_id']);
    }

    return [$chain, $entries];
}

/**
 * @param  list<array<string, mixed>>  $entries
 * @return list<string>
 */
function idsOf(array $entries): array
{
    return array_map(fn (array $entry): string => $entry['id'], $entries);
}

it('accepts a good batch, keeps it and moves the head of the chain', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 3);

    $response = pushAs($device, $entries)->assertOk();

    expect($response)->toMatchSchema('api/sync-push.response');
    expect($response->json())->toMatchArray(['accepted' => idsOf($entries), 'last_seq' => 3, 'rejected' => [], 'media_missing' => []]);
    $device->refresh();
    expect($device->last_seq)->toBe(3)->and($device->last_hash)->toBe($entries[2]['hash']);
    $stored = ChainEntry::query()->where('seq', 2)->firstOrFail();
    expect(ChainEntry::query()->orderBy('seq')->pluck('verify_status')->all())->toBe([VerifyStatus::Ok, VerifyStatus::Ok, VerifyStatus::Ok])
        ->and($stored->content())->toEqualCanonicalizing($entries[1])
        ->and($stored->flags)->toBe([])
        ->and(Incident::query()->count())->toBe(2);
});

it('says so when the device secret cannot be decrypted', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 1);
    // Encrypted with another application key, as after an APP_KEY change without APP_PREVIOUS_KEYS.
    DB::table(Table::name('devices'))->where('id', $device->id)->update(['secret' => (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString('x')]);
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (UnreadableDeviceSecret $e) use (&$reported): void {
        $reported[] = $e->getMessage();
    });

    $response = pushAs($device, $entries)->assertInternalServerError();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error'))->toBe(['code' => 'server_error', 'message' => 'A szerver nem tudja ellenőrizni ennek a telefonnak a feltöltéseit. A telefont újra hozzá kell rendelni a telephelyhez.']);
    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toContain($device->id, 'APP_PREVIOUS_KEYS');
    expect(ChainEntry::query()->count())->toBe(0)
        ->and($device->refresh()->last_seq)->toBe(0);
});

it('stores an entry so that its HMAC can be recomputed', function (): void {
    $device = Device::factory()->create();
    $chain = new ChainBuilder($device);
    $shift = $chain->shiftStarted();
    $incident = $chain->incident($shift['payload']['shift_id'], ['note' => "Árvíztűrő / tükörfúrógép\u{2028}\"\\\n"], [
        'gnss_time' => '2026-10-27T20:00:01.000Z',
        'ref_server_time' => '2026-10-27T19:58:02.511Z', 'ref_uptime_ms' => 900_000, 'ref_boot_id' => 'boot-1',
    ]);

    pushAs($device, [$shift, $incident])->assertOk();

    expect(EntryHash::matches(ChainEntry::query()->whereKey($incident['id'])->firstOrFail()->content(), $device->secret))->toBeTrue();
});

it('updates last_seen_at without the device showing up in the pull as changed', function (): void {
    travelTo('2026-10-27T10:00:00.000Z');
    $device = Device::factory()->create();
    $updatedAt = $device->refresh()->updated_at ?? throw new LogicException();

    travelTo('2026-10-27T12:00:00.000Z');
    [, $entries] = chainOf($device, 1);
    pushAs($device, $entries)->assertOk();

    $device->refresh();
    expect(Time::iso($device->last_seen_at ?? throw new LogicException()))->toBe('2026-10-27T12:00:00.000Z')
        ->and($device->updated_at?->equalTo($updatedAt))->toBeTrue();
});

it('accepts an entry sent again, and repeats nothing', function (): void {
    $device = Device::factory()->create();
    [$chain, $entries] = chainOf($device, 2);
    pushAs($device, $entries)->assertOk();
    $next = $chain->incident($entries[0]['payload']['shift_id']);

    $response = pushAs($device, [...$entries, $next])->assertOk();

    expect($response->json())->toMatchArray(['accepted' => idsOf([...$entries, $next]), 'last_seq' => 3, 'rejected' => []]);
    expect(ChainEntry::query()->count())->toBe(3)->and(Incident::query()->count())->toBe(2);
});

it('replays the response of the same Idempotency-Key', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 1);
    $headers = ['Authorization' => 'Bearer ' . deviceToken($device), 'Idempotency-Key' => (string) Str::uuid()];
    $body = ['device_id' => $device->id, 'entries' => $entries];

    requestApi('POST', 'sync/push', $headers, $body)->assertOk();
    $replayed = requestApi('POST', 'sync/push', $headers, $body)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($replayed->json('accepted'))->toBe(idsOf($entries));
});

it('rejects an entry after a gap, stores it and keeps it out of the projections', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 4);
    $log = packageLog();

    $response = pushAs($device, [$entries[0], $entries[3]])->assertOk();

    // Only a gap: the phone's own retry, not worth a warning.
    $log->shouldHaveReceived('log')->once()->with('info', 'Waypost: a push had rejected entries.', ['device_id' => $device->id, 'site_id' => $device->site_id, 'last_seq' => 1, 'rejected' => ['chain_gap' => 1]]);

    expect($response)->toMatchSchema('api/sync-push.response');
    expect($response->json())->toMatchArray([
        'accepted' => [$entries[0]['id']],
        'last_seq' => 1,
        'rejected' => [['id' => $entries[3]['id'], 'code' => 'chain_gap', 'expected_seq' => 2]],
    ]);
    $waiting = ChainEntry::query()->whereKey($entries[3]['id'])->firstOrFail();
    expect($waiting->verify_status)->toBe(VerifyStatus::Gap)
        ->and($waiting->projected_at)->toBeNull()
        ->and(Incident::query()->count())->toBe(0)
        ->and($device->refresh()->last_seq)->toBe(1);
});

it('fills a gap: 1, 2, 4, 5, then 3 makes all of them ok and the projection complete', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 5);

    $first = pushAs($device, [$entries[0], $entries[1], $entries[3], $entries[4]])->assertOk();
    expect($first->json('accepted'))->toBe(idsOf([$entries[0], $entries[1]]))
        ->and($first->json('rejected'))->toBe([
            ['id' => $entries[3]['id'], 'code' => 'chain_gap', 'expected_seq' => 3],
            ['id' => $entries[4]['id'], 'code' => 'chain_gap', 'expected_seq' => 3],
        ])
        ->and(Incident::query()->count())->toBe(1);

    $second = pushAs($device, [$entries[2]])->assertOk();

    expect($second)->toMatchSchema('api/sync-push.response');
    expect($second->json())->toMatchArray(['accepted' => idsOf([$entries[2], $entries[3], $entries[4]]), 'last_seq' => 5, 'rejected' => []]);
    expect(ChainEntry::query()->orderBy('seq')->pluck('verify_status')->unique()->all())->toBe([VerifyStatus::Ok])
        ->and(ChainEntry::query()->whereNull('projected_at')->count())->toBe(0)
        ->and(Incident::query()->count())->toBe(4)
        ->and($device->refresh()->last_hash)->toBe($entries[4]['hash']);
});

it('keeps a push whose entry cannot be applied, and applies that entry on a later push', function (): void {
    $device = Device::factory()->create();
    [$chain, $entries] = chainOf($device, 3);
    $log = packageLog();
    $failed = false;
    Incident::creating(function () use (&$failed): void {
        if (!$failed) {
            $failed = true;

            throw new RuntimeException('The disk is full.');
        }
    });

    $first = pushAs($device, $entries)->assertOk();

    expect($first->json())->toMatchArray(['accepted' => idsOf($entries), 'last_seq' => 3, 'rejected' => []])
        ->and(ChainEntry::query()->whereNull('projected_at')->pluck('seq')->all())->toBe([2])
        ->and(Incident::query()->count())->toBe(1);
    $log->shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $context['entry_id'] === $entries[1]['id'] && $context['seq'] === 2,
    );

    pushAs($device, [$chain->incident($entries[0]['payload']['shift_id'])])->assertOk();

    expect(ChainEntry::query()->whereNull('projected_at')->count())->toBe(0)
        ->and(Incident::query()->count())->toBe(3);
});

it('tries at most PENDING_LIMIT unapplied entries of earlier pushes, but always the ones it accepts now', function (): void {
    $device = Device::factory()->create();
    $chain = new ChainBuilder($device);
    $shiftId = Uuid::v7();
    $earlier = [];
    foreach (range(1, ChainProcessor::PENDING_LIMIT + 2) as $seq) {
        $entry = ChainEntry::fromPush($earlier[] = $chain->incident($shiftId));
        $entry->fill(['received_at' => Time::now(), 'verify_status' => VerifyStatus::Ok, 'flags' => []])->save();
    }
    $device->update(['last_seq' => $chain->seq, 'last_hash' => $chain->lastHash]);
    // The shift these incidents wait for arrives (here with one of their entries as its own).
    Shift::factory()->create(['id' => $shiftId, 'device_id' => $device->id, 'chain_entry_id' => $earlier[0]['id']]);
    $now = $chain->incident($shiftId);

    pushAs($device, [$now])->assertOk();

    expect(ChainEntry::query()->whereNull('projected_at')->orderBy('seq')->pluck('seq')->all())
        ->toBe([ChainProcessor::PENDING_LIMIT + 1, ChainProcessor::PENDING_LIMIT + 2]);

    pushAs($device, [$now])->assertOk();

    expect(ChainEntry::query()->whereNull('projected_at')->count())->toBe(0)
        ->and(Incident::query()->count())->toBe(count($earlier) + 1);
});

it('lists at most MEDIA_MISSING_LIMIT missing photos, the oldest first', function (): void {
    $device = Device::factory()->create();
    $ids = [];
    foreach (range(1, ChainProcessor::MEDIA_MISSING_LIMIT + 1) as $photo) {
        $ids[] = Media::factory()->create(['id' => Uuid::v7(), 'device_id' => $device->id, 'owner_id' => Uuid::v7()])->id;
    }
    [, $entries] = chainOf($device, 1);

    $response = pushAs($device, $entries)->assertOk();

    expect($response->json('media_missing'))->toBe(array_slice($ids, 0, ChainProcessor::MEDIA_MISSING_LIMIT));
});

it('takes the waiting entries in turn when the batch itself fills the gap between them', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 5);
    pushAs($device, [$entries[0], $entries[3]])->assertOk();

    // 2, 3 and 5 arrive; 4 waits between them.
    $response = pushAs($device, [$entries[1], $entries[2], $entries[4]])->assertOk();

    expect($response->json())->toMatchArray([
        'accepted' => idsOf([$entries[1], $entries[2], $entries[3], $entries[4]]),
        'last_seq' => 5,
        'rejected' => [],
    ]);
});

it('stops at a waiting entry that turns out to be wrong', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 4);
    // Signed by the device, but on another chain.
    $fork = ['prev_hash' => str_repeat('b', 64)] + $entries[2];
    $fork['hash'] = EntryHash::of($fork, $device->secret);

    pushAs($device, [$entries[0], $fork, $entries[3]])->assertOk();
    $response = pushAs($device, [$entries[1]])->assertOk();

    expect($response->json())->toMatchArray([
        'accepted' => [$entries[1]['id']],
        'last_seq' => 2,
        'rejected' => [['id' => $fork['id'], 'code' => 'chain_fork', 'expected_seq' => null]],
    ]);
    expect(ChainEntry::query()->whereKey($fork['id'])->firstOrFail()->verify_status)->toBe(VerifyStatus::Fork)
        ->and(ChainEntry::query()->whereKey($entries[3]['id'])->firstOrFail()->verify_status)->toBe(VerifyStatus::Gap);

    // The real entry replaces the wrong one, and the one waiting behind it follows.
    expect(pushAs($device, [$entries[2]])->assertOk()->json())->toMatchArray([
        'accepted' => idsOf([$entries[2], $entries[3]]),
        'last_seq' => 4,
        'rejected' => [],
    ]);
});

it('rejects a fork: the seq is right, the previous hash is not', function (): void {
    $device = Device::factory()->create();
    [$chain, $entries] = chainOf($device, 1);
    pushAs($device, $entries)->assertOk();
    $chain->lastHash = str_repeat('c', 64);
    $fork = $chain->incident($entries[0]['payload']['shift_id']);
    $log = packageLog();

    $response = pushAs($device, [$fork])->assertOk();

    $log->shouldHaveReceived('log')->once()->with('warning', 'Waypost: a push had rejected entries.', ['device_id' => $device->id, 'site_id' => $device->site_id, 'last_seq' => 1, 'rejected' => ['chain_fork' => 1]]);

    expect($response)->toMatchSchema('api/sync-push.response');
    expect($response->json())->toMatchArray([
        'accepted' => [],
        'last_seq' => 1,
        'rejected' => [['id' => $fork['id'], 'code' => 'chain_fork', 'expected_seq' => null]],
    ]);
    expect(ChainEntry::query()->whereKey($fork['id'])->firstOrFail()->verify_status)->toBe(VerifyStatus::Fork)
        ->and(Incident::query()->count())->toBe(0)
        ->and($device->refresh()->last_seq)->toBe(1);
});

it('checks the seq before the previous hash and the HMAC', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 2);

    // Both the previous hash and the hash are wrong too, but the server is still waiting for seq 1.
    $wrong = ['prev_hash' => str_repeat('c', 64), 'hash' => str_repeat('d', 64)] + $entries[1];

    expect(pushAs($device, [$wrong])->assertOk()->json('rejected'))->toBe([['id' => $wrong['id'], 'code' => 'chain_gap', 'expected_seq' => 1]]);
});

it('stores a gap entry only with the device\'s HMAC, and never lets a forged one replace a waiting one', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 4);
    pushAs($device, [$entries[0], $entries[3]])->assertOk();
    $forged = fn (array $entry): array => ['hash' => str_repeat('e', 64)] + $entry;

    $response = pushAs($device, [$forged($entries[2]), $forged($entries[3]), $forged(['id' => Uuid::v7(), 'seq' => 900] + $entries[2])])->assertOk();

    expect($response->json('rejected.*.code'))->toBe(['chain_gap', 'chain_gap', 'chain_gap'])
        ->and($response->json('rejected.*.expected_seq'))->toBe([2, 2, 2])
        ->and(ChainEntry::query()->orderBy('seq')->pluck('seq')->all())->toBe([1, 4])
        ->and(ChainEntry::query()->whereKey($entries[3]['id'])->value('hash'))->toBe($entries[3]['hash']);
    expect(pushAs($device, [$entries[1], $entries[2]])->assertOk()->json())->toMatchArray([
        'accepted' => idsOf([$entries[1], $entries[2], $entries[3]]), 'last_seq' => 4, 'rejected' => [],
    ]);
});

it('checks the previous hash before the HMAC', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 2);
    pushAs($device, [$entries[0]])->assertOk();
    $wrong = ['prev_hash' => str_repeat('c', 64), 'hash' => str_repeat('d', 64)] + $entries[1];

    expect(pushAs($device, [$wrong])->assertOk()->json('rejected.0.code'))->toBe('chain_fork');
});

it('refuses a first entry whose previous hash is not zeros', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 1);

    pushAs($device, [['prev_hash' => str_repeat('c', 64)] + $entries[0]])->assertUnprocessable();
});

it('rejects a bad HMAC, whatever was changed', function (array $change): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 1);
    $tampered = array_replace_recursive($entries[0], $change);

    $response = pushAs($device, [$tampered])->assertOk();

    expect($response)->toMatchSchema('api/sync-push.response');
    expect($response->json())->toMatchArray([
        'accepted' => [],
        'last_seq' => 0,
        'rejected' => [['id' => $tampered['id'], 'code' => 'bad_hmac', 'expected_seq' => null]],
    ]);
    expect(ChainEntry::query()->whereKey($tampered['id'])->firstOrFail()->verify_status)->toBe(VerifyStatus::BadHmac)
        ->and(Shift::query()->count())->toBe(0)
        ->and($device->refresh()->last_hash)->toBeNull();
})->with([
    'the hash' => [['hash' => str_repeat('d', 64)]],
    'the device time' => [['device_time' => '2026-10-27T20:30:00.000Z']],
    'the payload' => [['payload' => ['guard_id' => '019a2b3c-4d5e-7f00-8a00-000000000001']]],
]);

it('does not take an entry of another secret', function (): void {
    $device = Device::factory()->create();
    $other = Device::factory()->create();
    [, $entries] = chainOf($other, 1);

    $forged = ['device_id' => $device->id] + $entries[0];

    expect(pushAs($device, [$forged])->assertOk()->json('rejected.0.code'))->toBe('bad_hmac');
});

it('rejects another entry with the seq of an accepted one, and keeps the accepted one', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 2);
    pushAs($device, $entries)->assertOk();
    $other = (new ChainBuilder($device))->shiftStarted();

    $response = pushAs($device, [$other])->assertOk();

    expect($response->json())->toMatchArray([
        'accepted' => [],
        'last_seq' => 2,
        'rejected' => [['id' => $other['id'], 'code' => 'chain_fork', 'expected_seq' => null]],
    ]);
    expect(ChainEntry::query()->where('seq', 1)->sole()->id)->toBe($entries[0]['id']);
});

it('accepts an old entry the retention job has deleted, if it is the device\'s own', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 2);
    Device::query()->whereKey($device->id)->update(['last_seq' => 2, 'last_hash' => $entries[1]['hash']]);

    expect(pushAs($device, [$entries[0]])->assertOk()->json('accepted'))->toBe([$entries[0]['id']]);
    expect(pushAs($device, [['hash' => str_repeat('e', 64)] + $entries[0]])->assertOk()->json('rejected.0.code'))->toBe('bad_hmac');
    expect(ChainEntry::query()->count())->toBe(0);
});

it('refuses the ID of an entry of another device, without touching it', function (): void {
    $other = Device::factory()->create();
    [, $theirs] = chainOf($other, 1);
    pushAs($other, $theirs)->assertOk();

    $device = Device::factory()->create();
    $chain = new ChainBuilder($device);
    $mine = $chain->shiftStarted(fields: ['id' => $theirs[0]['id']]);

    expect(pushAs($device, [$mine])->assertOk()->json())->toMatchArray([
        'accepted' => [],
        'last_seq' => 0,
        'rejected' => [['id' => $mine['id'], 'code' => 'chain_fork', 'expected_seq' => null]],
    ]);
    expect(ChainEntry::query()->whereKey($theirs[0]['id'])->firstOrFail()->device_id)->toBe($other->id);
});

it('lets a rejected entry give way to an entry with its ID', function (): void {
    $device = Device::factory()->create();
    $id = Uuid::v7();
    $waiting = new ChainBuilder($device);
    $waiting->seq = 8;
    pushAs($device, [$waiting->shiftStarted(fields: ['id' => $id])])->assertOk();

    $real = (new ChainBuilder($device))->shiftStarted(fields: ['id' => $id]);

    expect(pushAs($device, [$real])->assertOk()->json('accepted'))->toBe([$id]);
    expect(ChainEntry::query()->whereKey($id)->firstOrFail()->seq)->toBe(1);
});

it('keeps the first arrival of an entry sent again while it waits', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 3);
    travelTo('2026-10-27T12:00:00.000Z');
    pushAs($device, [$entries[1]])->assertOk();

    travelTo('2026-10-27T13:00:00.000Z');
    pushAs($device, [$entries[0], $entries[1]])->assertOk();

    expect(Time::iso(ChainEntry::query()->whereKey($entries[1]['id'])->firstOrFail()->received_at))->toBe('2026-10-27T12:00:00.000Z')
        ->and(Time::iso(ChainEntry::query()->whereKey($entries[0]['id'])->firstOrFail()->received_at))->toBe('2026-10-27T13:00:00.000Z');
});

it('refuses a batch out of seq order as a whole', function (array $order): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 3);
    $batch = array_values(array_map(fn (int $index): array => $entries[$index], $order));

    $response = pushAs($device, $batch)->assertUnprocessable();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.code'))->toBe('validation_failed')
        ->and(array_keys($response->json('error.details')))->toBe(['entries.' . (count($order) - 1) . '.seq']);
    expect(ChainEntry::query()->count())->toBe(0);
})->with([
    'descending' => [[1, 0]],
    'the same seq twice' => [[0, 0]],
    'a later one out of place' => [[0, 2, 1]],
]);

it('refuses more than 200 entries, and no entry at all', function (): void {
    $device = Device::factory()->create();
    $chain = new ChainBuilder($device);
    $entries = [];
    $shift = $chain->shiftStarted();
    for ($i = 0; $i < 200; $i++) {
        $entries[] = $chain->incident($shift['payload']['shift_id']);
    }

    $response = pushAs($device, [$shift, ...$entries])->assertUnprocessable();
    expect($response->json('error.details'))->toHaveKey('entries');
    expect(ChainEntry::query()->count())->toBe(0);

    pushAs($device, [])->assertUnprocessable();
});

it('takes 200 entries in a batch', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 200);

    $response = pushAs($device, $entries)->assertOk();

    expect($response)->toMatchSchema('api/sync-push.response');
    expect($response->json('last_seq'))->toBe(200)->and(Incident::query()->count())->toBe(199);
});

it('lists at most 200 accepted entries; the rest is acknowledged when sent again', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 202);
    pushAs($device, array_slice($entries, 1, 200))->assertOk();

    $response = pushAs($device, [$entries[0]])->assertOk();

    expect($response)->toMatchSchema('api/sync-push.response');
    expect($response->json('accepted'))->toHaveCount(200)->and($response->json('last_seq'))->toBe(201);
    expect(pushAs($device, [$entries[200], $entries[201]])->assertOk()->json('accepted'))->toBe(idsOf([$entries[200], $entries[201]]));
});

it('refuses a device ID other than the token\'s, in the request and in the entries', function (): void {
    $device = Device::factory()->create();
    $other = Device::factory()->create();
    [, $entries] = chainOf($device, 1);

    $token = ['Authorization' => 'Bearer ' . deviceToken($device)];
    expect(requestApi('POST', 'sync/push', $token, ['device_id' => $other->id, 'entries' => $entries])->assertUnprocessable()->json('error.details'))
        ->toHaveKey('device_id');
    expect(pushAs($device, [['device_id' => $other->id] + $entries[0]])->assertUnprocessable()->json('error.details'))
        ->toHaveKey('entries.0.device_id');
});

it('validates the entries against the schema', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 1);

    pushAs($device, [['lat_e7' => 47.5] + $entries[0]])->assertUnprocessable();
    $missing = $entries[0];
    unset($missing['gnss_time']);
    pushAs($device, [$missing])->assertUnprocessable();
});

it('is only for the phone: a revoked one gets device_revoked', function (): void {
    $device = Device::factory()->revoked()->create();
    [, $entries] = chainOf($device, 1);

    expect(pushAs($device, $entries)->assertUnauthorized()->json('error.code'))->toBe('device_revoked');
    expect(ChainEntry::query()->count())->toBe(0);
});

it('needs an Idempotency-Key', function (): void {
    $device = Device::factory()->create();
    [, $entries] = chainOf($device, 1);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . deviceToken($device)];

    call('POST', 'api/waypost/v1/sync/push', server: $server, content: (string) json_encode(['device_id' => $device->id, 'entries' => $entries]))
        ->assertUnprocessable()
        ->assertJsonPath('error.details.Idempotency-Key.0', 'Az Idempotency-Key fejléc kötelező.');
});
