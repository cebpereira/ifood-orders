<?php

declare(strict_types=1);

namespace App\Ifood\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Base class for every failure talking to iFood. Carries what is needed to
 * debug the call with iFood support, and never the request body (it may hold
 * credentials).
 */
class IfoodException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $ifoodRequestId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function fromResponse(string $method, string $path, Response $response): static
    {
        [$code, $message] = self::errorDetails($response);

        // @phpstan-ignore new.static (subclasses keep this constructor signature)
        return new static(
            sprintf('iFood %s %s failed with %d%s', $method, $path, $response->status(), $message ? ": {$message}" : ''),
            $response->status(),
            $response->header('x-request-id') ?: null,
            $code,
            $message,
        );
    }

    /**
     * iFood uses two error shapes (docs/discovery.md):
     * {"error": {"code", "message"}} and {"code", "message"} at the root.
     *
     * @return array{?string, ?string}
     */
    public static function errorDetails(Response $response): array
    {
        $code = $response->json('error.code') ?? $response->json('code');
        $message = $response->json('error.message') ?? $response->json('message');

        return [is_string($code) ? $code : null, is_string($message) ? $message : null];
    }
}
