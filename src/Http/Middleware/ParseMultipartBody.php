<?php

declare(strict_types=1);

namespace Waypost\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use RequestParseBodyException;
use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * PHP fills $_POST and $_FILES only for POST, so the multipart body of a PUT is parsed here (request_parse_body(),
 * PHP 8.4). Runs before HandleIdempotencyKey, whose fingerprint counts the fields and the files. A request that already
 * holds its fields or files (a test, another server) is left alone.
 */
final class ParseMultipartBody
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('PUT')
            && str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'multipart/form-data')
            && $request->request->count() === 0
            && $request->files->count() === 0) {
            try {
                [$fields, $files] = request_parse_body();
            } catch (RequestParseBodyException) {
                throw new BadRequestHttpException();
            }
            $request->request = new InputBag($fields);
            $request->files = new FileBag($files);
        }

        return $next($request);
    }
}
