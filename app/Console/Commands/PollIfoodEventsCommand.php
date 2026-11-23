<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ifood\Events\EventIngestor;
use App\Ifood\Exceptions\IfoodException;
use App\Ifood\Exceptions\IfoodRateLimitedException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class PollIfoodEventsCommand extends Command
{
    protected $signature = 'ifood:poll';

    protected $description = 'Poll iFood order events, store them, dispatch processing and acknowledge';

    public function handle(EventIngestor $ingestor): int
    {
        try {
            $result = $ingestor->poll();
        } catch (IfoodRateLimitedException $e) {
            // Expected under load: skip this cycle, the next one runs in 30s.
            $this->warn("Rate limited, skipping cycle ({$e->retryAfter}s until reset).");

            return self::SUCCESS;
        } catch (IfoodException $e) {
            Log::channel('ifood')->error('ifood.polling.failed', ['error' => $e->getMessage(), 'status' => $e->status]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'received=%d new=%d duplicates=%d acked=%s',
            $result->received,
            $result->new,
            $result->duplicates,
            $result->acknowledged ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }
}
