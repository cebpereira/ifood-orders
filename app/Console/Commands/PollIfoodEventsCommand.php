<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ifood\Events\EventIngestor;
use App\Ifood\Events\PollingHealth;
use App\Ifood\Exceptions\IfoodException;
use App\Ifood\Exceptions\IfoodRateLimitedException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class PollIfoodEventsCommand extends Command
{
    protected $signature = 'ifood:poll';

    protected $description = 'Poll iFood order events, store them, dispatch processing and acknowledge';

    public function handle(EventIngestor $ingestor, PollingHealth $health): int
    {
        try {
            $result = $ingestor->poll();
        } catch (IfoodRateLimitedException $e) {
            // Expected under load: skip this cycle, the next one runs in 30s.
            $health->recordProblem('rate_limited', "Limite de requisições atingido, nova tentativa em {$e->retryAfter}s");
            $this->warn("Rate limited, skipping cycle ({$e->retryAfter}s until reset).");

            return self::SUCCESS;
        } catch (IfoodException $e) {
            Log::channel('ifood')->error('ifood.polling.failed', ['error' => $e->getMessage(), 'status' => $e->status]);
            $health->recordProblem('error', $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $health->recordSuccess($result);

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
