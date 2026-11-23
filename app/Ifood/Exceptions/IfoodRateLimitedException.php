<?php

declare(strict_types=1);

namespace App\Ifood\Exceptions;

/**
 * iFood answered 429, or a previous 429 is still in effect. Callers should not
 * retry before $retryAfter seconds (jobs use it with release()).
 */
final class IfoodRateLimitedException extends IfoodException
{
    public int $retryAfter = 0;

    public static function until(int $retryAfter, ?string $ifoodRequestId = null): self
    {
        $exception = new self(
            sprintf('iFood rate limit reached, retry in %ds', $retryAfter),
            429,
            $ifoodRequestId,
        );
        $exception->retryAfter = $retryAfter;

        return $exception;
    }
}
