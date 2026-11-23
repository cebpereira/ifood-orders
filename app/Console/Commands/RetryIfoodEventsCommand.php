<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ifood\Enums\IfoodEventStatus;
use App\Jobs\ProcessIfoodEventJob;
use App\Models\IfoodEvent;
use Illuminate\Console\Command;

final class RetryIfoodEventsCommand extends Command
{
    protected $signature = 'ifood:retry
        {--id=* : Retry these event ids (ifood_events.id) regardless of status or attempts}
        {--stale=120 : Seconds after which a received event is considered lost}
        {--limit=500 : Maximum events per run}
        {--dry-run : Only list what would be retried}';

    protected $description = 'Re-dispatch failed events whose backoff elapsed and received events nobody picked up';

    public function handle(): int
    {
        /** @var list<string> $ids */
        $ids = (array) $this->option('id');

        $query = $ids !== []
            ? IfoodEvent::query()->whereKey($ids)->where('status', '!=', IfoodEventStatus::Processed)
            : IfoodEvent::query()->dueForRetry(self::intOption($this->option('stale')));

        $events = $query->limit(self::intOption($this->option('limit')))->get();

        if ($events->isEmpty()) {
            $this->info('Nothing to retry.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'code', 'order', 'status', 'attempts', 'last error'],
            $events->map(fn (IfoodEvent $e): array => [
                $e->id,
                $e->code,
                substr($e->order_id, 0, 8),
                $e->status->value,
                $e->attempts,
                mb_strimwidth((string) $e->last_error, 0, 60, '…'),
            ])->all(),
        );

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        foreach ($events as $event) {
            // Back to received so the job (which skips final states) runs again.
            $event->forceFill(['status' => IfoodEventStatus::Received, 'next_retry_at' => null])->save();
            ProcessIfoodEventJob::dispatch($event->id);
        }

        $this->info("Dispatched {$events->count()} event(s).");

        return self::SUCCESS;
    }

    private static function intOption(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
