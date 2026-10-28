<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Waypost\Auth\Caller;
use Waypost\Enums\TagType;
use Waypost\Http\Entities;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Models\Checkpoint;
use Waypost\Models\Route;
use Waypost\Validation\SchemaValidator;

/**
 * /checkpoints (spec: Leolvasás, Betanítás): the points of the caller's sites. A tag UID is unique within a site,
 * a QR payload everywhere (among the checkpoints that are not deleted).
 */
final class CheckpointController extends Controller
{
    /** @return array{checkpoints: list<array<string, mixed>>} */
    public function index(Request $request, Caller $caller): array
    {
        $checkpoints = Checkpoint::query()
            ->whereIn('site_id', self::visibleSites($request, $caller))
            ->orderBy('name')->orderBy('id')
            ->get();

        return ['checkpoints' => array_values($checkpoints->map(Entities::checkpoint(...))->all())];
    }

    /** Without qr_payload the checkpoint gets a generated QR code (null: none). */
    public function store(Request $request, SchemaValidator $validator): JsonResponse
    {
        $input = self::input($request);
        $this->gate->authorize('create', [Checkpoint::class, $input['site_id']]);

        $checkpoint = new Checkpoint(['id' => self::newId($request, Checkpoint::class)]);
        $checkpoint->fill(array_key_exists('qr_payload', $input) ? $input : [...$input, 'qr_payload' => self::qrPayload($checkpoint->id)]);
        $this->save($checkpoint, $validator);

        return self::created(['checkpoint' => Entities::checkpoint($checkpoint)]);
    }

    /** @return array{checkpoint: array<string, mixed>} */
    public function show(Checkpoint $checkpoint): array
    {
        $this->gate->authorize('view', $checkpoint);

        return ['checkpoint' => Entities::checkpoint($checkpoint)];
    }

    /**
     * The result must be a valid checkpoint (entities/checkpoint.json): e.g. a new nfc tag type needs a tag UID. A
     * qr checkpoint without a QR code gets a generated one.
     *
     * @return array{checkpoint: array<string, mixed>}
     */
    public function update(Request $request, Checkpoint $checkpoint, SchemaValidator $validator): array
    {
        $this->gate->authorize('update', $checkpoint);

        $checkpoint->fill(self::input($request));
        if ($checkpoint->tag_type === TagType::Qr && $checkpoint->qr_payload === null) {
            $checkpoint->qr_payload = self::qrPayload($checkpoint->id);
        }
        $this->save($checkpoint, $validator);

        return ['checkpoint' => Entities::checkpoint($checkpoint)];
    }

    /**
     * A checkpoint of the current version of a route stays: its rounds would miss it. Older versions keep
     * referring to it, so it is only soft-deleted.
     *
     * @return array{}
     */
    public function destroy(Checkpoint $checkpoint): array
    {
        $this->gate->authorize('delete', $checkpoint);
        $used = Route::query()
            ->whereHas('currentVersion.points', fn (Builder $points) => $points->where('checkpoint_id', $checkpoint->id))
            ->exists();
        if ($used) {
            throw ApiException::conflict('A pont szerepel egy útvonal jelenlegi verziójában, előbb vedd ki belőle.');
        }

        $checkpoint->delete();

        return [];
    }

    private function save(Checkpoint $checkpoint, SchemaValidator $validator): void
    {
        if ($checkpoint->tag_uid !== null) {
            $checkpoint->tag_uid = Str::upper(str_replace(':', '', $checkpoint->tag_uid));
        }
        if ($checkpoint->qr_payload !== null && !str_starts_with($checkpoint->qr_payload, "waypost:cp:{$checkpoint->id}:")) {
            self::invalid('qr_payload', 'A QR-kód egy másik pont azonosítóját tartalmazza.');
        }
        $checkpoint->updateTimestamps();
        $validator->assertValid(Entities::checkpoint($checkpoint), 'entities/checkpoint');

        if ($checkpoint->tag_uid !== null && self::others($checkpoint)->where('site_id', $checkpoint->site_id)->where('tag_uid', $checkpoint->tag_uid)->exists()) {
            throw ApiException::conflict('Ez a címke már egy másik ponthoz tartozik ezen a telephelyen.', ['tag_uid' => ['Foglalt címke.']]);
        }
        if ($checkpoint->qr_payload !== null && self::others($checkpoint)->where('qr_payload', $checkpoint->qr_payload)->exists()) {
            throw ApiException::conflict('Ez a QR-kód már egy másik ponthoz tartozik.', ['qr_payload' => ['Foglalt QR-kód.']]);
        }

        self::write(fn () => $checkpoint->save(), 'Ez a címke vagy QR-kód már egy másik ponthoz tartozik.');
    }

    /** @return Builder<Checkpoint> */
    private static function others(Checkpoint $checkpoint): Builder
    {
        return Checkpoint::query()->whereKeyNot($checkpoint->id);
    }

    /** waypost:cp:<checkpoint id>:<16 random characters [A-Za-z0-9]> */
    private static function qrPayload(string $id): string
    {
        return "waypost:cp:{$id}:" . Str::random(16);
    }
}
