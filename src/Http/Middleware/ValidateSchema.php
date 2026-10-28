<?php

declare(strict_types=1);

namespace Waypost\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Validation\SchemaValidator;

/**
 * Validates the JSON body against a request schema of waypost/schema (ValidateSchema::using('api/…request')),
 * before any FormRequest: those only check what needs the database. Errors: validation_failed with the
 * messages per field.
 *
 * The validated body then replaces the request's JSON input, so the controller gets exactly what the schema
 * accepted, untouched by the host's global middleware (TrimStrings, ConvertEmptyStringsToNull).
 */
final readonly class ValidateSchema
{
    public function __construct(private SchemaValidator $validator)
    {
    }

    public static function using(string $schema): string
    {
        return self::class . ':' . $schema;
    }

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string $schema): Response
    {
        if (!$request->isJson()) {
            throw ApiException::invalidBody('A kérés törzsének JSON-nak kell lennie (Content-Type: application/json).');
        }

        try {
            $data = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ApiException::invalidBody('A kérés törzse nem érvényes JSON.');
        }
        if (!$data instanceof stdClass) {
            throw ApiException::invalidBody('A kérés törzsének JSON-objektumnak kell lennie.');
        }

        $errors = $this->validator->errors($data, $schema);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var array<string, mixed> $input */
        $input = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        // Laravel reads JSON input from both bags (json() and request).
        $bag = new InputBag($input);
        $request->setJson($bag);
        $request->request = $bag;

        return $next($request);
    }
}
