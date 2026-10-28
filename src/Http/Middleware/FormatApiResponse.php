<?php

declare(strict_types=1);

namespace Waypost\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Waypost\Http\ErrorRenderer;
use Waypost\Support\Time;

/**
 * The outermost middleware of the package's routes:
 * - an error response (one the host's exception handler rendered, with the exception on it) is rendered again
 *   in the API's error format, whatever the handler is;
 * - server_time (the current time, common/server-time.json) goes first into every JSON object response. It
 *   replaces a value already there, so a replayed idempotent response also tells the current time. An empty
 *   array (a controller returning []) becomes an object.
 */
final readonly class FormatApiResponse
{
    public function __construct(private ErrorRenderer $errors)
    {
    }

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $exception = property_exists($response, 'exception') ? $response->exception : null;
        if ($exception instanceof Throwable && ($rendered = $this->errors->render($exception, $request)) !== null) {
            $response = $rendered->withException($exception);
        }
        if (!$response instanceof JsonResponse) {
            return $response;
        }

        $data = $response->getData();
        if ($data === []) {
            $data = new stdClass();
        }
        if ($data instanceof stdClass) {
            $response->setData((object) (['server_time' => Time::iso(Time::now())] + get_object_vars($data)));
        }

        return $response;
    }
}
