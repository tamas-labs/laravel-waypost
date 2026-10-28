<?php

declare(strict_types=1);

namespace Waypost\Http\Middleware;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Waypost\Auth\Caller;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Models\Device;
use Waypost\Models\IdempotencyKey;
use Waypost\Models\User;
use Waypost\Support\Time;

/**
 * The Idempotency-Key of a write request (spec: API-szerződés): a UUID, required. The response to a key is
 * stored for 24 hours, and a repeated request with the same key gets it again (only server_time is new, see
 * FormatApiResponse) with an Idempotent-Replayed header, without running again. Keys belong to the token's owner,
 * so two callers never share one. Runs after Authenticate.
 *
 * - The same key with another request (method, path, body): validation_failed.
 * - The same key while its first request still runs: conflict (409), to retry later.
 * - A server error (5xx) is not stored: the request may be retried with the same key.
 */
final class HandleIdempotencyKey
{
    public const string HEADER = 'Idempotency-Key';

    public const string REPLAYED_HEADER = 'Idempotent-Replayed';

    public const int TTL_HOURS = 24;

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $owner = Caller::of($request)?->owner() ?? throw new LogicException('HandleIdempotencyKey runs after Authenticate.');
        $key = $this->key($request);
        $fingerprint = $this->fingerprint($request);

        $record = $this->find($owner, $key);
        if ($record === null) {
            try {
                // A savepoint: on PostgreSQL a failed insert would abort an enclosing transaction.
                $record = DB::transaction(fn (): IdempotencyKey => $this->create($owner, $key, $request, $fingerprint));
            } catch (UniqueConstraintViolationException) {
                // The same key arrived concurrently.
                $record = $this->find($owner, $key) ?? throw ApiException::conflict(self::busyMessage());
            }
            if ($record->wasRecentlyCreated) {
                return $this->run($request, $next, $record);
            }
        }

        return $this->replay($record, $fingerprint);
    }

    private function key(Request $request): string
    {
        $key = $request->headers->get(self::HEADER);
        if ($key === null || !Str::isUuid($key)) {
            throw ValidationException::withMessages([self::HEADER => [
                $key === null ? 'Az Idempotency-Key fejléc kötelező.' : 'Az Idempotency-Key fejlécnek UUID-nak kell lennie.',
            ]]);
        }

        return Str::lower($key);
    }

    /**
     * SHA-256 of the method, the path, the query and the body. A multipart body counts by its fields and the
     * contents of its files, because the boundary changes from one attempt to the next.
     */
    private function fingerprint(Request $request): string
    {
        $context = hash_init('sha256');
        hash_update($context, implode("\n", [$request->method(), $request->getPathInfo(), (string) $request->getQueryString(), '']));

        $files = Arr::dot($request->allFiles());
        if ($files === []) {
            hash_update($context, $request->getContent());
        } else {
            $fields = Arr::dot($request->request->all());
            ksort($fields);
            $contents = array_map(
                fn (mixed $file): ?string => $file instanceof UploadedFile ? hash_file('sha256', $file->getPathname()) ?: null : null,
                $files,
            );
            ksort($contents);
            hash_update($context, (string) json_encode([$fields, $contents]));
        }

        return hash_final($context);
    }

    private function find(User|Device $owner, string $key): ?IdempotencyKey
    {
        $record = IdempotencyKey::query()->whereMorphedTo('owner', $owner)->where('key', $key)->first();
        if ($record !== null && $record->expires_at <= Time::now()) {
            // Expired, but not cleaned up yet: as if it were new.
            $record->delete();

            return null;
        }

        return $record;
    }

    private function create(User|Device $owner, string $key, Request $request, string $fingerprint): IdempotencyKey
    {
        $record = new IdempotencyKey([
            'key' => $key,
            'method' => $request->method(),
            'path' => Str::limit($request->getPathInfo(), 255, ''),
            'request_hash' => $fingerprint,
            'expires_at' => Time::now()->addHours(self::TTL_HOURS),
        ]);
        $record->owner()->associate($owner);
        $record->save();

        return $record;
    }

    /** @param  Closure(Request): Response  $next */
    private function run(Request $request, Closure $next, IdempotencyKey $record): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $record->delete();

            throw $e;
        }

        if ($response->getStatusCode() >= 500 || !$response instanceof JsonResponse) {
            $record->delete();
        } else {
            $record->update(['response_status' => $response->getStatusCode(), 'response_body' => $response->getContent()]);
        }

        return $response;
    }

    private function replay(IdempotencyKey $record, string $fingerprint): Response
    {
        if (!hash_equals($record->request_hash, $fingerprint)) {
            throw ValidationException::withMessages([self::HEADER => ['Ezt az Idempotency-Key-t már egy másik kéréshez használtad.']]);
        }
        if ($record->response_status === null || $record->response_body === null) {
            throw ApiException::conflict(self::busyMessage());
        }

        return JsonResponse::fromJsonString($record->response_body, $record->response_status, [self::REPLAYED_HEADER => 'true']);
    }

    private static function busyMessage(): string
    {
        return 'Ugyanez a kérés még feldolgozás alatt van, próbáld újra később.';
    }
}
