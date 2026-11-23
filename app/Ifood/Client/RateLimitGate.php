<?php

declare(strict_types=1);

namespace App\Ifood\Client;

use App\Ifood\Exceptions\IfoodRateLimitedException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

/**
 * Shared "back off until" marker. After a 429 every worker stops calling iFood
 * until the reset time, instead of each one burning requests to find out.
 */
final class RateLimitGate
{
    public const string CACHE_KEY = 'ifood:rate-limited-until';

    public function __construct(
        private readonly Cache $cache,
        private readonly int $defaultBackoff,
    ) {}

    /** @throws IfoodRateLimitedException while a previous 429 is in effect */
    public function ensureOpen(): void
    {
        $until = $this->cache->get(self::CACHE_KEY);

        if (is_int($until) && $until > Carbon::now()->getTimestamp()) {
            throw IfoodRateLimitedException::until($until - Carbon::now()->getTimestamp());
        }
    }

    /** Close the gate based on a 429 response and return the seconds to wait. */
    public function closeFrom(Response $response): int
    {
        $retryAfter = $this->retryAfter($response);
        $until = Carbon::now()->getTimestamp() + $retryAfter;

        $this->cache->put(self::CACHE_KEY, $until, $retryAfter);

        return $retryAfter;
    }

    /**
     * X-RateLimit-Reset was not observed during discovery (no 429 happened), so
     * both common shapes are accepted: seconds until reset, or a Unix timestamp.
     */
    private function retryAfter(Response $response): int
    {
        foreach (['X-RateLimit-Reset', 'Retry-After'] as $header) {
            $value = $response->header($header);
            if (! is_numeric($value)) {
                continue;
            }

            $value = (int) $value;
            $seconds = $value > 1_000_000_000 ? $value - Carbon::now()->getTimestamp() : $value;

            if ($seconds > 0) {
                return min($seconds, 3600);
            }
        }

        return $this->defaultBackoff;
    }
}
