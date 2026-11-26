<?php

namespace App\Http;

use Exception;

/**
 * Binding ApiError payload (ARCHITECTURE.md §4.1). Thrown by controllers and
 * domain services; rendered by ApiErrorFormatter into the
 * { error: { code, message, details? } } shape.
 */
class ApiException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message, 0, null);
    }

    public static function unauthorized(string $message = 'Authentication required.'): self
    {
        return new self('unauthorized', 401, $message);
    }

    public static function forbidden(string $message = 'Not allowed for this role.'): self
    {
        return new self('forbidden', 403, $message);
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self('not_found', 404, $message);
    }

    public static function conflict(string $message, array $details = []): self
    {
        return new self('conflict', 409, $message, $details);
    }

    public static function invalidState(string $message, array $details = []): self
    {
        return new self('invalid_state', 422, $message, $details);
    }

    public static function validation(string $message, array $details = []): self
    {
        return new self('validation_failed', 422, $message, $details);
    }

    public static function internal(string $message = 'Internal server error.'): self
    {
        return new self('internal', 500, $message);
    }
}
