<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers;

use Illuminate\Http\Request;
use LogicException;
use Waypost\Auth\Caller;
use Waypost\Chain\ChainProcessor;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Models\Device;
use Waypost\Support\Time;
use Waypost\Sync\Pull;

/** GET /sync/pull, POST /sync/push, POST /sync/push-final (spec: API-szerződés, Szinkron protokoll). */
final class SyncController extends Controller
{
    /**
     * A page of the configuration changes since ?cursor= (none or empty: the full state).
     *
     * @return array<string, mixed>
     */
    public function pull(Request $request, Caller $caller, Pull $pull): array
    {
        $cursor = $request->query('cursor');
        if ($cursor !== null && !is_string($cursor)) {
            self::invalid('cursor', 'Hibás kurzor; kurzor nélkül a teljes állapot kérhető le.');
        }
        $page = $pull->page($caller, $cursor === '' ? null : $cursor);

        // Without updated_at: otherwise every pull would bring the phone itself again as a change.
        if ($caller->device !== null) {
            $caller->device->timestamps = false;
            $caller->device->update(['last_seen_at' => Time::now()]);
        }

        return $page;
    }

    /**
     * A batch of the phone's chain entries.
     *
     * @return array<string, mixed>
     */
    public function push(Request $request, Caller $caller, ChainProcessor $chain): array
    {
        $device = $caller->device ?? throw new LogicException('Only a device pushes.');

        return $chain->process($device, self::entries($request, $device));
    }

    /**
     * A batch of the last upload of a revoked phone; done: true closes the upload, whatever the batch's result.
     *
     * @return array<string, mixed>
     */
    public function pushFinal(Request $request, Caller $caller, ChainProcessor $chain): array
    {
        $device = $caller->device ?? throw new LogicException('Only a device pushes.');
        if ($device->revoked_at === null) {
            throw ApiException::forbidden('Ez a végpont csak visszavont telefonnak szól.');
        }

        return $chain->process($device, self::entries($request, $device), closeUpload: $request->json('done') === true);
    }

    /**
     * The entries of the body: all of the calling device (the token's, api/sync-push.request.json), in ascending seq order,
     * each photo at most once in an entry's media (a rule the schema cannot express: waypost/schema decisions, SCH-016).
     *
     * @return list<array<string, mixed>>
     */
    private static function entries(Request $request, Device $device): array
    {
        if ($request->json('device_id') !== $device->id) {
            self::invalid('device_id', 'Nem ennek a telefonnak az azonosítója.');
        }

        /** @var list<array<string, mixed>> $entries */
        $entries = $request->json('entries');
        $previous = 0;
        foreach ($entries as $index => $entry) {
            if ($entry['device_id'] !== $device->id) {
                self::invalid("entries.{$index}.device_id", 'Nem ennek a telefonnak az azonosítója.');
            }
            if ($entry['seq'] <= $previous) {
                self::invalid("entries.{$index}.seq", 'A bejegyzéseknek seq szerint növekvő sorrendben kell lenniük.');
            }
            $previous = $entry['seq'];
            $position = self::repeatedPhoto($entry);
            if ($position !== null) {
                self::invalid("entries.{$index}.payload.media.{$position}.id", 'Ez a fotó már szerepel a bejegyzésben.');
            }
        }

        return $entries;
    }

    /**
     * The position in the entry's media where a photo ID comes the second time: the chain would hold two hashes for
     * one photo, and its upload would fail with one of them for good.
     *
     * @param  array<string, mixed>  $entry  chain/entry.json
     */
    private static function repeatedPhoto(array $entry): ?int
    {
        $payload = $entry['payload'];
        $media = is_array($payload) && is_array($payload['media'] ?? null) ? $payload['media'] : [];
        $seen = [];
        foreach (array_values($media) as $position => $photo) {
            $id = is_array($photo) ? $photo['id'] ?? null : null;
            if (!is_string($id)) {
                continue;
            }
            if (isset($seen[$id])) {
                return $position;
            }
            $seen[$id] = true;
        }

        return null;
    }
}
