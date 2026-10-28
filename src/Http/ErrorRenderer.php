<?php

declare(strict_types=1);

namespace Waypost\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use Waypost\Enums\ErrorCode;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Support\Time;

/**
 * Renders every exception of a request under the API prefix as api/error.json, with server_time. Used twice:
 * - by FormatApiResponse on the package's routes, on the response the host's exception handler rendered, so it
 *   works whatever that handler is (e.g. decorated by Collision on the command line);
 * - as a render callback of the host's handler, if that is Laravel's, for errors outside the package's routes
 *   (unknown endpoint, maintenance mode, the host's global middleware).
 *
 * The exception may be the original one (on a route) or as the handler prepared it (in the callback). Reporting
 * is left to the host.
 */
final readonly class ErrorRenderer
{
    /** Messages of statuses that have no code of their own. */
    private const array STATUS_MESSAGES = [
        405 => 'Ez a végpont ezt a kérésmódot nem támogatja.',
        413 => 'A kérés túl nagy.',
        415 => 'A kérés tartalomtípusa nem támogatott.',
        503 => 'A szolgáltatás átmenetileg nem érhető el, próbáld újra később.',
    ];

    /** AuthorizationException's message when the policy gave none. */
    private const string LARAVEL_DENIAL = 'This action is unauthorized.';

    public function __construct(private Repository $config)
    {
    }

    /** Whether the request is under the API prefix (waypost.route.prefix). */
    public function handles(Request $request): bool
    {
        $prefix = trim((string) $this->config->get('waypost.route.prefix'), '/');

        return $prefix === '' || $request->is($prefix, "{$prefix}/*");
    }

    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (!$this->handles($request) || $e instanceof HttpResponseException) {
            return null;
        }

        return match (true) {
            $e instanceof ApiException => self::response($e->errorCode, $e->getMessage(), $e->details, $e->getHeaders()),
            $e instanceof AuthenticationException => self::response(ErrorCode::Unauthenticated),
            $e instanceof AuthorizationException => $e->status() === 404
                ? self::response(ErrorCode::NotFound)
                : self::response(ErrorCode::Forbidden, self::denial($e)),
            $e instanceof ModelNotFoundException,
            $e instanceof RecordNotFoundException,
            $e instanceof RecordsNotFoundException,
            $e instanceof BackedEnumCaseNotFoundException => self::response(ErrorCode::NotFound),
            $e instanceof ValidationException => self::response(ErrorCode::ValidationFailed, details: $e->errors()),
            // A unique index caught a conflict the validation did not (e.g. a concurrent request).
            $e instanceof UniqueConstraintViolationException => self::response(ErrorCode::Conflict),
            $e instanceof HttpExceptionInterface => $this->httpError($e, $request),
            default => self::response(ErrorCode::ServerError),
        };
    }

    /** The reason a policy gave (in Hungarian), not Laravel's English default. */
    private static function denial(AuthorizationException $e): ?string
    {
        return $e->getMessage() === self::LARAVEL_DENIAL ? null : $e->getMessage();
    }

    /**
     * @param  array<string, array<int, string>>  $details  messages keyed by the dot-separated field path
     * @param  array<string, mixed>  $headers
     */
    public static function response(
        ErrorCode $code,
        ?string $message = null,
        array $details = [],
        array $headers = [],
        ?int $status = null,
    ): JsonResponse {
        $error = ['code' => $code->value, 'message' => $message ?? $code->message()];
        if ($details !== []) {
            // An object even if the keys look like list indexes.
            $error['details'] = (object) array_map(array_values(...), $details);
        }

        return new JsonResponse(
            ['server_time' => Time::iso(Time::now()), 'error' => $error],
            $status ?? $code->status(),
            $headers,
        );
    }

    /** An HTTP exception of the framework, e.g. a missing model, a denied policy, throttling or a wrong method. */
    private function httpError(HttpExceptionInterface $e, Request $request): JsonResponse
    {
        $status = $e->getStatusCode();
        $code = match (true) {
            $status === 401 => ErrorCode::Unauthenticated,
            $status === 403 => ErrorCode::Forbidden,
            $status === 404 => ErrorCode::NotFound,
            $status === 409 => ErrorCode::Conflict,
            $status === 422 => ErrorCode::ValidationFailed,
            $status === 429 => ErrorCode::RateLimited,
            $status >= 500 => ErrorCode::ServerError,
            default => ErrorCode::BadRequest,
        };
        // No route matched: an unknown endpoint, not a missing record.
        $message = $status === 404 && $request->route() === null ? 'Nincs ilyen végpont.' : (self::STATUS_MESSAGES[$status] ?? null);

        return self::response($code, $message, headers: $e->getHeaders(), status: $status);
    }
}
