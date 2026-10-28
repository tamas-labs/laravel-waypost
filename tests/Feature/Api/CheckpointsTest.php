<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Waypost\Database\Factories\CheckpointFactory;
use Waypost\Models\Checkpoint;
use Waypost\Models\Route;
use Waypost\Models\RouteVersion;
use Waypost\Models\RouteVersionCheckpoint;
use Waypost\Models\Site;
use Waypost\Support\Uuid;

pest()->use(RefreshDatabase::class);

it('creates an NFC checkpoint with a normalized tag UID and a generated QR code', function (): void {
    $site = Site::factory()->create();

    $response = callApi('POST', 'checkpoints', supervisorOf($site), [
        'site_id' => $site->id, 'name' => 'Raktár hátsó ajtó', 'tag_type' => 'nfc', 'tag_uid' => '04:a1:b2:c3:d4:e5:80',
        'lat_e7' => 474979000, 'lng_e7' => 190402000, 'accuracy_dm' => 125,
    ])->assertCreated();

    expect($response)->toMatchSchema('api/checkpoint.response');
    $id = $response->json('checkpoint.id');
    expect($response->json('checkpoint'))->toMatchArray([
        'site_id' => $site->id,
        'tag_uid' => '04A1B2C3D4E580',
        'lat_e7' => 474979000,
        'photo_media_id' => null,
        'active' => true,
    ]);
    expect($response->json('checkpoint.qr_payload'))->toMatch('/^waypost:cp:' . $id . ':[A-Za-z0-9]{16}$/D');
});

it('creates a checkpoint without a QR code, or with the given one', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $id = Uuid::v7();

    callApi('POST', 'checkpoints', $supervisor, ['site_id' => $site->id, 'name' => 'Kapu', 'tag_type' => 'nfc_dna', 'tag_uid' => '04112233445566', 'qr_payload' => null])
        ->assertCreated()->assertJsonPath('checkpoint.qr_payload', null);
    callApi('POST', 'checkpoints', $supervisor, ['id' => $id, 'site_id' => $site->id, 'name' => 'Hátsó kapu', 'tag_type' => 'qr', 'qr_payload' => "waypost:cp:{$id}:Zz09Yy18Xx27Ww36"])
        ->assertCreated()->assertJsonPath('checkpoint.qr_payload', "waypost:cp:{$id}:Zz09Yy18Xx27Ww36");
});

it('refuses a QR code of another checkpoint', function (): void {
    $site = Site::factory()->create();
    $other = Uuid::v7();

    $response = callApi('POST', 'checkpoints', supervisorOf($site), ['site_id' => $site->id, 'name' => 'Kapu', 'tag_type' => 'qr', 'qr_payload' => "waypost:cp:{$other}:Zz09Yy18Xx27Ww36"])
        ->assertUnprocessable();

    expect($response->json('error.details'))->toBe(['qr_payload' => ['A QR-kód egy másik pont azonosítóját tartalmazza.']]);
});

it('refuses a tag UID used on the site, whatever its form', function (): void {
    [$site, $other] = [Site::factory()->create(), Site::factory()->create()];
    Checkpoint::factory()->for($site)->create(['tag_uid' => '04A1B2C3D4E580']);
    Checkpoint::factory()->for($other)->create(['tag_uid' => '0102030405060708']);
    $supervisor = supervisorOf($site);

    $response = callApi('POST', 'checkpoints', $supervisor, ['site_id' => $site->id, 'name' => 'Kapu', 'tag_type' => 'nfc', 'tag_uid' => '04:A1:B2:C3:D4:E5:80'])
        ->assertConflict();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error'))->toBe([
        'code' => 'conflict',
        'message' => 'Ez a címke már egy másik ponthoz tartozik ezen a telephelyen.',
        'details' => ['tag_uid' => ['Foglalt címke.']],
    ]);
    // A tag of another site is free here.
    callApi('POST', 'checkpoints', $supervisor, ['site_id' => $site->id, 'name' => 'Kapu', 'tag_type' => 'nfc', 'tag_uid' => '0102030405060708'])->assertCreated();
});

it('reuses the tag UID of a deleted checkpoint', function (): void {
    $site = Site::factory()->create();
    Checkpoint::factory()->for($site)->create(['tag_uid' => 'A1B2C3D4'])->delete();
    $checkpoint = Checkpoint::factory()->for($site)->create();

    callApi('PATCH', "checkpoints/{$checkpoint->id}", supervisorOf($site), ['tag_uid' => 'a1b2c3d4'])->assertOk()->assertJsonPath('checkpoint.tag_uid', 'A1B2C3D4');
});

it('refuses a QR code another checkpoint has, also on another site', function (): void {
    $site = Site::factory()->create();
    $checkpoint = Checkpoint::factory()->for($site)->create();
    $payload = CheckpointFactory::qrPayload($checkpoint->id);
    Checkpoint::factory()->create(['qr_payload' => $payload]);

    $response = callApi('PATCH', "checkpoints/{$checkpoint->id}", supervisorOf($site), ['qr_payload' => $payload])->assertConflict();

    expect($response->json('error'))->toBe([
        'code' => 'conflict',
        'message' => 'Ez a QR-kód már egy másik ponthoz tartozik.',
        'details' => ['qr_payload' => ['Foglalt QR-kód.']],
    ]);
});

it('keeps a changed checkpoint valid', function (array $body, array $details): void {
    $site = Site::factory()->create();
    $checkpoint = Checkpoint::factory()->for($site)->create(['tag_type' => 'qr', 'tag_uid' => null, 'qr_payload' => null]);

    $response = callApi('PATCH', "checkpoints/{$checkpoint->id}", supervisorOf($site), $body)->assertUnprocessable();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.details'))->toBe($details);
})->with([
    'nfc without a tag UID' => [['tag_type' => 'nfc'], ['tag_uid' => ['Meg kell adni.']]],
    'latitude without longitude' => [['lat_e7' => 474979000], ['lng_e7' => ['Kötelező mező.']]],
]);

it('gives a QR checkpoint a QR code when it has none', function (): void {
    $site = Site::factory()->create();
    $checkpoint = Checkpoint::factory()->for($site)->create(['qr_payload' => null]);

    $response = callApi('PATCH', "checkpoints/{$checkpoint->id}", supervisorOf($site), ['tag_type' => 'qr', 'name' => 'Kapu', 'instruction' => null])->assertOk();

    expect($response)->toMatchSchema('api/checkpoint.response');
    expect($response->json('checkpoint'))->toMatchArray(['tag_type' => 'qr', 'name' => 'Kapu', 'tag_uid' => $checkpoint->tag_uid]);
    expect($response->json('checkpoint.qr_payload'))->toStartWith("waypost:cp:{$checkpoint->id}:");
});

it('lists the checkpoints of the caller\'s sites', function (): void {
    [$a, $b] = [Site::factory()->create(), Site::factory()->create()];
    $first = Checkpoint::factory()->for($a)->create(['name' => 'A']);
    $second = Checkpoint::factory()->for($b)->create(['name' => 'B']);
    Checkpoint::factory()->create();
    $supervisor = supervisorOf($a, $b);

    $response = callApi('GET', 'checkpoints', $supervisor)->assertOk();

    expect($response)->toMatchSchema('api/checkpoint-list.response');
    expect($response->json('checkpoints.*.id'))->toBe([$first->id, $second->id]);
    expect(callApi('GET', "checkpoints?site_id={$b->id}", $supervisor)->json('checkpoints.*.id'))->toBe([$second->id]);
});

it('creates checkpoints only on the caller\'s sites, and hides the others', function (): void {
    [$own, $foreign] = [Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($own);
    $theirs = Checkpoint::factory()->for($foreign)->create();

    callApi('POST', 'checkpoints', $supervisor, ['site_id' => $foreign->id, 'name' => 'Kapu', 'tag_type' => 'qr'])->assertForbidden();
    callApi('GET', "checkpoints/{$theirs->id}", $supervisor)->assertNotFound();
    callApi('PATCH', "checkpoints/{$theirs->id}", $supervisor, ['name' => 'X'])->assertNotFound();
    callApi('DELETE', "checkpoints/{$theirs->id}", $supervisor)->assertNotFound();
});

it('deletes a checkpoint, but not one of the current version of a route', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $route = Route::factory()->for($site)->create();
    [$used, $old] = [Checkpoint::factory()->for($site)->create(), Checkpoint::factory()->for($site)->create()];
    $first = RouteVersion::factory()->for($route)->create();
    RouteVersionCheckpoint::factory()->create(['route_version_id' => $first->id, 'checkpoint_id' => $old->id, 'position' => 1]);
    $current = RouteVersion::factory()->for($route)->create();
    RouteVersionCheckpoint::factory()->create(['route_version_id' => $current->id, 'checkpoint_id' => $used->id, 'position' => 1]);
    $route->update(['current_version_id' => $current->id]);

    $response = callApi('DELETE', "checkpoints/{$used->id}", $supervisor)->assertConflict();
    expect($response->json('error.message'))->toBe('A pont szerepel egy útvonal jelenlegi verziójában, előbb vedd ki belőle.');

    expect(callApi('DELETE', "checkpoints/{$old->id}", $supervisor)->assertOk())->toMatchSchema('api/deleted.response');
    expect($old->refresh()->trashed())->toBeTrue();
});
