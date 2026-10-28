<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Waypost\Auth\Caller;
use Waypost\Auth\Gate;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Models\Model;
use Waypost\Support\Uuid;

/**
 * Base of the configuration endpoints. The body has passed the waypost/schema validation (ValidateSchema), so a
 * controller only checks what depends on the database. It returns the body without server_time (FormatApiResponse
 * adds it); an error is an ApiException or a ValidationException.
 */
abstract class Controller
{
    public function __construct(protected readonly Gate $gate)
    {
    }

    /**
     * The validated JSON body.
     *
     * @return array<string, mixed>
     */
    protected static function input(Request $request): array
    {
        return $request->json()->all();
    }

    /**
     * 201 Created.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function created(array $data): JsonResponse
    {
        return new JsonResponse($data, JsonResponse::HTTP_CREATED);
    }

    /**
     * The id of a new record: the one the client generated (for its outbox), or a new UUIDv7. An id that is
     * already taken, also by a deleted record, is a conflict.
     *
     * @param  class-string<Model>  $model
     */
    protected static function newId(Request $request, string $model): string
    {
        $id = $request->json('id');
        if (!is_string($id)) {
            return Uuid::v7();
        }
        if (DB::table((new $model())->getTable())->where('id', $id)->exists()) {
            throw ApiException::conflict('Ezzel az azonosítóval már létezik rekord.', ['id' => ['Foglalt azonosító.']]);
        }

        return $id;
    }

    /** A UUIDv7 query parameter (e.g. ?site_id=), null if absent. */
    protected static function uuidParameter(Request $request, string $name): ?string
    {
        $value = $request->query($name);
        if ($value === null) {
            return null;
        }
        if (!Uuid::isV7($value)) {
            throw ValidationException::withMessages([$name => ['Hibás formátum.']]);
        }

        return $value;
    }

    /**
     * The caller's sites, or only the one of ?site_id= (none if that is not the caller's).
     *
     * @return list<string>
     */
    protected static function visibleSites(Request $request, Caller $caller): array
    {
        $siteId = self::uuidParameter($request, 'site_id');

        return $siteId === null ? $caller->siteIds() : array_values(array_intersect($caller->siteIds(), [$siteId]));
    }

    /**
     * Runs the writes in a savepoint (on PostgreSQL a failed statement aborts an enclosing transaction); a unique
     * constraint violation (a concurrent request won the race the checks before lost) is a conflict.
     *
     * @template T
     *
     * @param  Closure(): T  $writes
     * @return T
     */
    protected static function write(Closure $writes, string $conflict = 'Az adat ütközik egy meglévővel.'): mixed
    {
        try {
            return DB::transaction($writes);
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict($conflict);
        }
    }

    /** @throws ValidationException */
    protected static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
