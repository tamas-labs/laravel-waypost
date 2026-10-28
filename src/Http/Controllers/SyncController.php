<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers;

use Illuminate\Http\Request;
use Waypost\Auth\Caller;
use Waypost\Support\Time;
use Waypost\Sync\Pull;

/** GET /sync/pull (spec: API-szerződés / Pull, Szinkron protokoll / Pull). */
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
}
