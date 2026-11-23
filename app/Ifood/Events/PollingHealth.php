<?php

declare(strict_types=1);

namespace App\Ifood\Events;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Outcome of the last polling cycle, shown in the panel header. It answers
 * "is the integration alive?" without opening logs. iFood only keeps the
 * store online while polling runs, so a stale value matters.
 */
final class PollingHealth
{
    private const string CACHE_KEY = 'ifood:polling-health';

    public function __construct(private readonly Cache $cache) {}

    public function recordSuccess(IngestResult $result): void
    {
        $this->cache->forever(self::CACHE_KEY, [
            'at' => now()->toIso8601String(),
            'state' => $result->acknowledged ? 'ok' : 'ack_failed',
            'received' => $result->received,
            'message' => null,
        ]);
    }

    public function recordProblem(string $state, string $message): void
    {
        $this->cache->forever(self::CACHE_KEY, [
            'at' => now()->toIso8601String(),
            'state' => $state,
            'received' => 0,
            'message' => $message,
        ]);
    }

    /** @return array{at: string, state: string, received: int, message: string|null}|null */
    public function last(): ?array
    {
        $value = $this->cache->get(self::CACHE_KEY);

        /** @var array{at: string, state: string, received: int, message: string|null}|null */
        return is_array($value) ? $value : null;
    }
}
