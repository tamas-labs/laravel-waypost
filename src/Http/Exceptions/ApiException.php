<?php

declare(strict_types=1);

namespace Waypost\Http\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Waypost\Enums\ErrorCode;

/**
 * An API error with its code, Hungarian message and optional per-field details (api/error.json). An HTTP
 * exception, so the host application does not report it.
 */
final class ApiException extends HttpException
{
    /**
     * @param  array<string, list<string>>  $details  messages keyed by the dot-separated field path
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly array $details = [],
        array $headers = [],
    ) {
        parent::__construct($errorCode->status(), $message ?? $errorCode->message(), null, $headers);
    }

    public static function forbidden(?string $message = null): self
    {
        return new self(ErrorCode::Forbidden, $message);
    }

    public static function deviceRevoked(): self
    {
        return new self(ErrorCode::DeviceRevoked);
    }

    /** @param  array<string, list<string>>  $details */
    public static function conflict(string $message, array $details = []): self
    {
        return new self(ErrorCode::Conflict, $message, $details);
    }

    /** A malformed request body as a whole (not a field of it). */
    public static function invalidBody(string $message): self
    {
        return new self(ErrorCode::ValidationFailed, $message);
    }
}
