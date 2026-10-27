<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A deliberate, client-safe API failure. Its message is returned verbatim, so
 * it must never contain secrets, payloads or internals.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function unauthenticated(string $code = 'unauthenticated', string $message = 'Authentication is required.'): self
    {
        return new self($code, $message, 401);
    }

    public static function forbidden(string $code, string $message): self
    {
        return new self($code, $message, 403);
    }

    public static function notFound(string $code = 'not_found', string $message = 'The requested resource was not found.'): self
    {
        return new self($code, $message, 404);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self($code, $message, 409);
    }

    public static function serverError(string $message = 'The server could not complete the request.'): self
    {
        return new self('server_error', $message, 500);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $this->errorCode, 'message' => $this->getMessage()],
        ], $this->status);
    }
}
