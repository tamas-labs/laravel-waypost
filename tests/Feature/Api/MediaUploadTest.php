<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Waypost\Enums\MediaOwnerType;
use Waypost\Models\Checkpoint;
use Waypost\Models\Device;
use Waypost\Models\Incident;
use Waypost\Models\Media;
use Waypost\Models\Site;
use Waypost\Support\Time;
use Waypost\Support\Uuid;
use Waypost\Tests\Fixtures\ChainBuilder;

use function Pest\Laravel\call;
use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * Pushes an incident citing a photo of the bytes; returns the photo's ID, the incident's ID and the entries pushed.
 *
 * @return array{string, string, list<array<string, mixed>>}
 */
function pushedPhoto(Device $device, string $bytes): array
{
    $chain = new ChainBuilder($device);
    $shift = $chain->shiftStarted();
    $photoId = Uuid::v7();
    $incident = $chain->incident($shift['payload']['shift_id'], ['media' => [['id' => $photoId, 'sha256' => hash('sha256', $bytes)]]]);
    expect(pushAs($device, [$shift, $incident])->assertOk()->json('media_missing'))->toBe([$photoId]);

    return [$photoId, $incident['payload']['incident_id'], [$shift, $incident]];
}

/** @return array<string, string> */
function photoFields(string $ownerType, string $ownerId, string $bytes): array
{
    return ['sha256' => hash('sha256', $bytes), 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'taken_at' => '2026-10-27T20:04:05.123Z'];
}

/** @return array<string, string> */
function deviceHeader(Device $device): array
{
    return ['Authorization' => 'Bearer ' . deviceToken($device)];
}

it('lists a cited photo as missing with its placeholder row, until it is uploaded', function (): void {
    $device = Device::factory()->create();
    $bytes = jpegBytes();
    [$id, $incidentId, $entries] = pushedPhoto($device, $bytes);

    $photo = Media::query()->whereKey($id)->firstOrFail();
    expect($photo->device_id)->toBe($device->id)
        ->and($photo->owner_type)->toBe('waypost.incident')
        ->and($photo->owner_id)->toBe($incidentId)
        ->and($photo->sha256)->toBe(hash('sha256', $bytes))
        ->and($photo->path)->toBeNull()
        ->and($photo->uploaded_at)->toBeNull()
        ->and(Time::iso($photo->taken_at))->toBe('2026-10-27T20:00:00.000Z');

    uploadMedia($id, photoFields('incident', $incidentId, $bytes), deviceHeader($device), $bytes)->assertOk();

    expect(pushAs($device, $entries)->assertOk()->json('media_missing'))->toBe([]);
});

it('stores the photo of an entry on the private disk', function (): void {
    $device = Device::factory()->create();
    $bytes = jpegBytes();
    [$id, $incidentId] = pushedPhoto($device, $bytes);

    $response = uploadMedia($id, photoFields('incident', $incidentId, $bytes), deviceHeader($device), $bytes)->assertOk();

    expect($response)->toMatchSchema('api/media-upload.response');
    expect($response->json('media'))->toMatchArray([
        'id' => $id,
        'device_id' => $device->id,
        'owner_type' => 'incident',
        'owner_id' => $incidentId,
        'mime' => 'image/jpeg',
        'size_bytes' => strlen($bytes),
        'taken_at' => '2026-10-27T20:04:05.123Z',
    ])->and($response->json('media'))->not->toHaveKey('path');
    $photo = Media::query()->whereKey($id)->firstOrFail();
    expect($photo->uploaded_at)->not->toBeNull()->and($photo->path)->toBe("waypost/media/{$id}.jpg");
    Storage::disk('local')->assertExists("waypost/media/{$id}.jpg");
    expect(Storage::disk('local')->get("waypost/media/{$id}.jpg"))->toBe($bytes)
        ->and(Storage::disk('local')->getVisibility("waypost/media/{$id}.jpg"))->toBe('private');
});

it('takes no taken_at later than the server\'s time, before or after the upload', function (): void {
    $device = Device::factory()->create();
    $chain = new ChainBuilder($device);
    $shift = $chain->shiftStarted();
    $bytes = jpegBytes();
    $photoId = Uuid::v7();
    $incident = $chain->incident($shift['payload']['shift_id'], ['media' => [['id' => $photoId, 'sha256' => hash('sha256', $bytes)]]], ['device_time' => '2036-10-27T20:00:00.000Z']);
    travelTo(Time::fromIso('2026-10-27T20:10:00.000Z'));
    pushAs($device, [$shift, $incident])->assertOk();

    expect(Time::iso(Media::query()->findOrFail($photoId)->taken_at))->toBe('2026-10-27T20:10:00.000Z');

    travelTo(Time::fromIso('2026-10-27T20:15:00.000Z'));
    $fields = [...photoFields('incident', $incident['payload']['incident_id'], $bytes), 'taken_at' => '2036-10-27T20:04:05.123Z'];
    $response = uploadMedia($photoId, $fields, deviceHeader($device), $bytes)->assertOk();

    expect($response->json('media.taken_at'))->toBe('2026-10-27T20:15:00.000Z');
});

it('gives the photo of a manual entry to its scan, and its incident cites the same', function (): void {
    $site = Site::factory()->create();
    $device = Device::factory()->for($site)->create();
    $checkpoint = Checkpoint::factory()->for($site)->create();
    $chain = new ChainBuilder($device);
    $shift = $chain->shiftStarted();
    $photo = ['id' => Uuid::v7(), 'sha256' => hash('sha256', jpegBytes())];
    $scan = $chain->scan(null, $checkpoint->id, [
        'method' => 'manual', 'tag_uid' => null, 'note' => 'Sérült címke.', 'flags' => ['manual', 'out_of_round'], 'media' => [$photo],
    ]);
    $incident = $chain->incident($shift['payload']['shift_id'], ['category' => 'damaged_tag', 'scan_id' => $scan['payload']['scan_id'], 'media' => [$photo]]);

    $response = pushAs($device, [$shift, $scan, $incident])->assertOk();

    expect($response->json('media_missing'))->toBe([$photo['id']]);
    $row = Media::query()->sole();
    expect($row->owner_type)->toBe('waypost.scan')->and($row->owner_id)->toBe($scan['payload']['scan_id']);
});

it('refuses a file whose hash differs from the one it tells, or from the entry\'s', function (): void {
    $device = Device::factory()->create();
    $bytes = jpegBytes('a');
    $other = jpegBytes('b');
    [$id, $incidentId] = pushedPhoto($device, $bytes);

    $told = uploadMedia($id, photoFields('incident', $incidentId, $bytes), deviceHeader($device), $other)->assertUnprocessable();
    expect($told)->toMatchSchema('api/error');
    expect($told->json('error.details'))->toBe(['sha256' => ['A fájl lenyomata nem egyezik a megadottal.']]);

    $entry = uploadMedia($id, photoFields('incident', $incidentId, $other), deviceHeader($device), $other)->assertUnprocessable();
    expect($entry->json('error.details'))->toBe(['sha256' => ['A fájl lenyomata nem egyezik a bejegyzésben rögzítettel.']]);

    expect(Media::query()->whereKey($id)->firstOrFail()->uploaded_at)->toBeNull();
    Storage::disk('local')->assertMissing("waypost/media/{$id}.jpg");
});

it('is forbidden for another device', function (): void {
    $device = Device::factory()->create();
    $bytes = jpegBytes();
    [$id, $incidentId] = pushedPhoto($device, $bytes);

    $response = uploadMedia($id, photoFields('incident', $incidentId, $bytes), deviceHeader(Device::factory()->create()), $bytes)->assertForbidden();

    expect($response)->toMatchSchema('api/error');
    expect(Media::query()->whereKey($id)->firstOrFail()->uploaded_at)->toBeNull();
});

it('is forbidden for a supervisor, as a photo of an entry', function (): void {
    $site = Site::factory()->create();
    $bytes = jpegBytes();
    [$id, $incidentId] = pushedPhoto(Device::factory()->for($site)->create(), $bytes);

    uploadMedia($id, photoFields('incident', $incidentId, $bytes), ['Authorization' => 'Bearer ' . supervisorToken(supervisorOf($site))], $bytes)->assertForbidden();
});

it('changes nothing when the same file arrives again', function (): void {
    $device = Device::factory()->create();
    $bytes = jpegBytes();
    [$id, $incidentId] = pushedPhoto($device, $bytes);
    $first = uploadMedia($id, photoFields('incident', $incidentId, $bytes), deviceHeader($device), $bytes)->assertOk();

    $second = uploadMedia($id, ['taken_at' => '2026-10-27T22:00:00.000Z'] + photoFields('incident', $incidentId, $bytes), deviceHeader($device), $bytes)->assertOk();

    expect($second)->toMatchSchema('api/media-upload.response');
    expect($second->json('media'))->toBe($first->json('media'));
    expect(uploadMedia($id, photoFields('incident', $incidentId, jpegBytes('b')), deviceHeader($device), jpegBytes('b'))->status())->toBe(422);
});

it('answers 404 before the entry is accepted', function (): void {
    $device = Device::factory()->create();
    $bytes = jpegBytes();

    $response = uploadMedia(Uuid::v7(), photoFields('scan', Uuid::v7(), $bytes), deviceHeader($device), $bytes)->assertNotFound();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.code'))->toBe('not_found');
    expect(Media::query()->count())->toBe(0);
});

it('has no row for a photo of an entry that was rejected', function (): void {
    $device = Device::factory()->create();
    $chain = new ChainBuilder($device);
    $shift = $chain->shiftStarted();
    $photoId = Uuid::v7();
    $bytes = jpegBytes();
    $incident = $chain->incident($shift['payload']['shift_id'], ['media' => [['id' => $photoId, 'sha256' => hash('sha256', $bytes)]]]);

    expect(pushAs($device, [$incident])->assertOk()->json('media_missing'))->toBe([]);
    uploadMedia($photoId, photoFields('incident', $incident['payload']['incident_id'], $bytes), deviceHeader($device), $bytes)->assertNotFound();
});

it('refuses a photo told to belong to something else', function (): void {
    $device = Device::factory()->create();
    $bytes = jpegBytes();
    [$id, $incidentId] = pushedPhoto($device, $bytes);

    expect(uploadMedia($id, photoFields('scan', $incidentId, $bytes), deviceHeader($device), $bytes)->assertUnprocessable()->json('error.details'))->toHaveKey('owner_id');
    expect(uploadMedia($id, photoFields('incident', Uuid::v7(), $bytes), deviceHeader($device), $bytes)->assertUnprocessable()->json('error.details'))->toHaveKey('owner_id');
});

it('validates the fields and the file', function (): void {
    $device = Device::factory()->create();
    $bytes = jpegBytes();
    [$id, $incidentId] = pushedPhoto($device, $bytes);
    $fields = photoFields('incident', $incidentId, $bytes);

    expect(uploadMedia($id, array_diff_key($fields, ['sha256' => '']), deviceHeader($device), $bytes)->assertUnprocessable()->json('error.details'))->toHaveKey('sha256');
    expect(uploadMedia($id, ['owner_type' => 'site'] + $fields, deviceHeader($device), $bytes)->assertUnprocessable()->json('error.details'))->toHaveKey('owner_type');
    expect(uploadMedia($id, ['mime' => 'image/png'] + $fields, deviceHeader($device), $bytes)->assertUnprocessable()->json('error.details'))->toHaveKey('mime');
    expect(uploadMedia($id, $fields, deviceHeader($device))->assertUnprocessable()->json('error.details'))->toBe(['file' => ['Kötelező mező.']]);
    expect(uploadMedia($id, ['sha256' => hash('sha256', 'not a photo')] + $fields, deviceHeader($device), 'not a photo')->assertUnprocessable()->json('error.details'))
        ->toBe(['file' => ['Csak JPEG fájl tölthető fel.']]);
});

it('stores the photo of a checkpoint that does not exist yet, then the checkpoint refers to it', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $checkpointId = Uuid::v7();
    $id = Uuid::v7();
    $bytes = jpegBytes();

    $response = uploadMedia($id, photoFields('checkpoint', $checkpointId, $bytes), ['Authorization' => 'Bearer ' . supervisorToken($supervisor)], $bytes)->assertOk();
    expect($response)->toMatchSchema('api/media-upload.response');
    expect($response->json('media'))->toMatchArray(['device_id' => null, 'owner_type' => 'checkpoint', 'owner_id' => $checkpointId]);

    callApi('POST', 'checkpoints', $supervisor, ['id' => $checkpointId, 'site_id' => $site->id, 'name' => 'Kapu', 'tag_type' => 'nfc', 'tag_uid' => '04112233445566', 'photo_media_id' => $id])
        ->assertCreated()
        ->assertJsonPath('checkpoint.photo_media_id', $id);
});

it('takes the photo of a checkpoint from the site phone with X-Acting-User, but not without it', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $phone = Device::factory()->for($site)->create();
    $checkpoint = Checkpoint::factory()->for($site)->create();
    $bytes = jpegBytes();
    $fields = photoFields('checkpoint', $checkpoint->id, $bytes);

    uploadMedia(Uuid::v7(), $fields, deviceHeader($phone), $bytes)->assertForbidden();

    $response = uploadMedia(Uuid::v7(), $fields, deviceHeader($phone) + ['X-Acting-User' => $supervisor->id], $bytes)->assertOk();
    expect($response->json('media.device_id'))->toBe($phone->id);
});

it('hides a checkpoint of another site, also a deleted one', function (bool $deleted): void {
    $checkpoint = Checkpoint::factory()->create();
    if ($deleted) {
        $checkpoint->delete();
    }
    $bytes = jpegBytes();

    uploadMedia(Uuid::v7(), photoFields('checkpoint', $checkpoint->id, $bytes), ['Authorization' => 'Bearer ' . supervisorToken(supervisorOf(Site::factory()->create()))], $bytes)
        ->assertNotFound();
    expect(Media::query()->count())->toBe(0);
})->with(['existing' => [false], 'deleted' => [true]]);

it('takes the same checkpoint photo again, and no other file under its ID', function (): void {
    $site = Site::factory()->create();
    $headers = ['Authorization' => 'Bearer ' . supervisorToken(supervisorOf($site))];
    $checkpointId = Uuid::v7();
    $id = Uuid::v7();
    $bytes = jpegBytes();

    uploadMedia($id, photoFields('checkpoint', $checkpointId, $bytes), $headers, $bytes)->assertOk();
    uploadMedia($id, photoFields('checkpoint', $checkpointId, $bytes), $headers, $bytes)->assertOk();
    uploadMedia($id, photoFields('checkpoint', $checkpointId, jpegBytes('b')), $headers, jpegBytes('b'))->assertUnprocessable();
    uploadMedia($id, photoFields('checkpoint', Uuid::v7(), $bytes), $headers, $bytes)->assertUnprocessable();
});

it('refuses a photo_media_id that is missing, not uploaded, or of another checkpoint', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $checkpoint = Checkpoint::factory()->for($site)->create();
    $notUploaded = Media::factory()->create(['owner_type' => MediaOwnerType::Checkpoint->stored(), 'owner_id' => $checkpoint->id]);
    $ofAnother = Media::factory()->uploaded()->create(['owner_type' => MediaOwnerType::Checkpoint->stored(), 'owner_id' => Uuid::v7()]);
    $ofIncident = Media::factory()->uploaded()->create(['owner_id' => $checkpoint->id]);

    foreach ([Uuid::v7(), $notUploaded->id, $ofAnother->id, $ofIncident->id] as $id) {
        expect(callApi('PATCH', "checkpoints/{$checkpoint->id}", $supervisor, ['photo_media_id' => $id])->assertUnprocessable()->json('error.details'))
            ->toBe(['photo_media_id' => ['Nincs ilyen feltöltött fotó ehhez a ponthoz.']]);
    }
    expect($checkpoint->refresh()->photo_media_id)->toBeNull();
});

it('sets and removes the photo of a checkpoint', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $checkpoint = Checkpoint::factory()->for($site)->create();
    $photo = Media::factory()->uploaded()->create(['owner_type' => MediaOwnerType::Checkpoint->stored(), 'owner_id' => $checkpoint->id]);

    callApi('PATCH', "checkpoints/{$checkpoint->id}", $supervisor, ['photo_media_id' => $photo->id])->assertOk()->assertJsonPath('checkpoint.photo_media_id', $photo->id);
    // Renaming does not check the photo again.
    callApi('PATCH', "checkpoints/{$checkpoint->id}", $supervisor, ['name' => 'Új név'])->assertOk()->assertJsonPath('checkpoint.photo_media_id', $photo->id);
    callApi('PATCH', "checkpoints/{$checkpoint->id}", $supervisor, ['photo_media_id' => null])->assertOk()->assertJsonPath('checkpoint.photo_media_id', null);
});

it('takes the photos of a revoked device until it closes its upload', function (): void {
    $device = Device::factory()->create();
    $bytes = jpegBytes();
    [$id, $incidentId] = pushedPhoto($device, $bytes);
    $device->update(['revoked_at' => Time::now()]);
    $fields = photoFields('incident', $incidentId, $bytes);

    uploadMedia($id, $fields, deviceHeader($device), $bytes)->assertOk();

    pushAs($device, [], done: true)->assertOk();
    $closed = uploadMedia($id, $fields, deviceHeader($device), $bytes)->assertUnauthorized();
    expect($closed)->toMatchSchema('api/error');
    expect($closed->json('error.code'))->toBe('device_revoked');
});

it('does not let a revoked device upload the photo of a checkpoint, with or without X-Acting-User', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $device = Device::factory()->for($site)->revoked()->create();
    $bytes = jpegBytes();
    $fields = photoFields('checkpoint', Uuid::v7(), $bytes);

    uploadMedia(Uuid::v7(), $fields, deviceHeader($device), $bytes)->assertForbidden();
    uploadMedia(Uuid::v7(), $fields, deviceHeader($device) + ['X-Acting-User' => $supervisor->id], $bytes)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'device_revoked');
});

it('answers bad_request for a multipart body it cannot parse', function (): void {
    $response = uploadMedia(Uuid::v7(), [], deviceHeader(Device::factory()->create()))->assertBadRequest();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.code'))->toBe('bad_request');
});

it('answers 413 for a file over the upload limit of PHP', function (): void {
    $device = Device::factory()->create();
    $bytes = jpegBytes();
    [$id, $incidentId] = pushedPhoto($device, $bytes);
    $path = (string) tempnam(sys_get_temp_dir(), 'waypost');
    file_put_contents($path, $bytes);

    $response = call('PUT', "api/waypost/v1/media/{$id}", photoFields('incident', $incidentId, $bytes), [], [
        'file' => new UploadedFile($path, 'photo.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true),
    ], [
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'multipart/form-data; boundary=test',
        'HTTP_AUTHORIZATION' => 'Bearer ' . deviceToken($device), 'HTTP_IDEMPOTENCY_KEY' => Uuid::v7(),
    ]);

    $response->assertStatus(413);
    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.code'))->toBe('bad_request');
});

it('answers 404 for an ID that is a UUID but not a UUIDv7', function (): void {
    $device = Device::factory()->create();
    $bytes = jpegBytes();

    uploadMedia('11111111-1111-4111-8111-111111111111', photoFields('checkpoint', Uuid::v7(), $bytes), deviceHeader($device), $bytes)->assertNotFound();
});

it('replays the response of the same Idempotency-Key, counting the contents of the file', function (): void {
    $site = Site::factory()->create();
    $key = '019a2b3c-4d5e-7f00-8a00-000000000abc';
    $headers = ['Authorization' => 'Bearer ' . supervisorToken(supervisorOf($site)), 'Idempotency-Key' => $key];
    $id = Uuid::v7();
    $checkpointId = Uuid::v7();
    $bytes = jpegBytes();

    uploadMedia($id, photoFields('checkpoint', $checkpointId, $bytes), $headers, $bytes)->assertOk();
    uploadMedia($id, photoFields('checkpoint', $checkpointId, $bytes), $headers, $bytes)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    uploadMedia($id, photoFields('checkpoint', $checkpointId, $bytes), $headers, jpegBytes('b'))->assertUnprocessable();
});
