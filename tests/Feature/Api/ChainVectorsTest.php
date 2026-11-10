<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Waypost\Chain\CanonicalJson;
use Waypost\Chain\EntryHash;
use Waypost\Enums\VerifyStatus;
use Waypost\Models\ChainEntry;
use Waypost\Models\Device;

/*
 * The raw entry texts of chain/test-vectors.json (normalized, invalid) through the push, as the phone sends them: the
 * verifier the backend actually runs (body decoding, schema, the controller's own rules, canonical JSON, HMAC) has to
 * decide on each as the contract says.
 */

pest()->use(RefreshDatabase::class);

/** The device of the vectors, with their key, its chain at seq 8 with the prev_hash the entries link to. */
function vectorDevice(): Device
{
    $vectors = chainVectors();
    /** @var array{device_id: string, seq: int, prev_hash: string} $entry */
    $entry = json_decode($vectors['normalized']['cases'][0]['entry_json'], true, flags: JSON_THROW_ON_ERROR);

    return Device::factory()->create([
        'id' => $entry['device_id'],
        'secret' => $vectors['key_base64'],
        'last_seq' => $entry['seq'] - 1,
        'last_hash' => $entry['prev_hash'],
    ]);
}

/**
 * POST /sync/push with the entry text as it is in the vector, not decoded and encoded again.
 *
 * @return Illuminate\Testing\TestResponse<Symfony\Component\HttpFoundation\Response>
 */
function pushRawEntry(Device $device, string $entryJson): Illuminate\Testing\TestResponse
{
    return requestApi('POST', 'sync/push', ['Authorization' => 'Bearer ' . deviceToken($device)], sprintf('{"device_id":"%s","entries":[%s]}', $device->id, $entryJson));
}

it('accepts every normalized entry text, with the canonical JSON and hash of the vector', function (string $name): void {
    $case = collect(chainVectors()['normalized']['cases'])->firstWhere('name', $name);
    expect($case)->not->toBeNull();
    /** @var array{entry_json: string, canonical_json: string, hash: string} $case */
    $device = vectorDevice();

    $response = pushRawEntry($device, $case['entry_json'])->assertOk();

    $stored = ChainEntry::query()->where('device_id', $device->id)->sole();
    expect($response->json())->toMatchArray(['accepted' => [$stored->id], 'rejected' => [], 'last_seq' => $stored->seq])
        ->and($stored->verify_status)->toBe(VerifyStatus::Ok)
        ->and($stored->hash)->toBe($case['hash'])
        // What is stored is the entry itself: its canonical JSON (without hash) and HMAC are the vector's.
        ->and(CanonicalJson::encode(Arr::except($stored->content(), 'hash')))->toBe($case['canonical_json'])
        ->and(EntryHash::of($stored->content(), chainVectors()['key_base64']))->toBe($case['hash']);
})->with(fn (): array => array_column(chainVectors()['normalized']['cases'], 'name'));

it('refuses every invalid entry text as the vector expects', function (string $name): void {
    $case = collect(chainVectors()['invalid']['cases'])->firstWhere('name', $name);
    expect($case)->not->toBeNull();
    /** @var array{expected: 'invalid'|'bad_hmac', entry_json: string} $case */
    $device = vectorDevice();
    $lastSeq = $device->last_seq;

    $response = pushRawEntry($device, $case['entry_json']);

    if ($case['expected'] === 'invalid') {
        $response->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        expect(ChainEntry::query()->count())->toBe(0);
    } else {
        $response->assertOk();
        expect($response->json('accepted'))->toBe([])
            ->and($response->json('rejected.0.code'))->toBe('bad_hmac')
            ->and($device->refresh()->last_seq)->toBe($lastSeq);
    }
})->with(fn (): array => array_column(chainVectors()['invalid']['cases'], 'name'));
