<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\SoftDeletingScope;
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
     * 200 OK for a create repeated with the same id (existing()): nothing was created now.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function repeated(array $data): JsonResponse
    {
        return new JsonResponse($data);
    }

    /**
     * A create repeated with the same id (spec: Outbox; waypost-schema: the id of the create requests): an earlier
     * attempt created the record, but the app did not get the response (its Idempotency-Key expired, or it sent a
     * new key). The record, if it is the same create: it has the request's unchanging fields ($same: its parent,
     * e.g. site_id, or the role of a user) and the caller sees it (the view of its policy; a model without a policy,
     * a route version, through the parent in $same that the controller has authorized). The controller answers
     * with it as it is now (repeated()), and changes nothing: the rest of the body is not compared, the record may
     * have changed since. Null if the request has no id or the id is new. A deleted record: conflict, telling so;
     * another one with this id: conflict, as a taken id.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @param  array<string, mixed>  $same
     * @return T|null
     */
    protected function existing(Request $request, string $model, array $same = []): ?Model
    {
        $id = $request->json('id');
        if (!is_string($id)) {
            return null;
        }
        $record = $model::query()->withoutGlobalScope(SoftDeletingScope::class)->find($id);
        if (!$record instanceof $model) {
            return null;
        }

        foreach ($same as $field => $value) {
            $actual = $record->getAttribute($field);
            if (($actual instanceof BackedEnum ? $actual->value : $actual) !== $value) {
                throw self::idTaken();
            }
        }
        // Before the policy: the caller no longer sees a deleted site.
        if ($record->getAttribute('deleted_at') !== null) {
            throw ApiException::conflict('Ezzel az azonosítóval már létrejött egy rekord, de azóta törölték.', ['id' => ['Törölt rekord azonosítója.']]);
        }
        if ($this->gate->getPolicyFor($record) !== null && $this->gate->inspect('view', $record)->denied()) {
            throw self::idTaken();
        }

        return $record;
    }

    /**
     * The id of a new record: the one the client generated (for its outbox), or a new UUIDv7. Called after
     * existing(), so a taken id, also of a deleted record, is a conflict.
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
            throw self::idTaken();
        }

        return $id;
    }

    private static function idTaken(): ApiException
    {
        return ApiException::conflict('Ezzel az azonosítóval már létezik rekord.', ['id' => ['Foglalt azonosító.']]);
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
