<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ifood\Events\EventProcessor;
use App\Ifood\Exceptions\IfoodRateLimitedException;
use App\Ifood\Exceptions\OrderNotYetKnownException;
use App\Models\IfoodEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes one stored iFood event. Safe to run more than once for the same
 * event (duplicate dispatch, ifood:retry, a worker crash): the row is locked
 * and an event already processed or ignored is skipped.
 */
final class ProcessIfoodEventJob implements ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'ifood-events';

    /** Exceptions tolerated per run before failed() marks the event for ifood:retry. */
    public int $maxExceptions = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $ifoodEventId)
    {
        $this->onQueue(self::QUEUE);
    }

    /** Releases (lock contention, 429) do not count as exceptions, so cap by time instead of tries. */
    public function retryUntil(): Carbon
    {
        return now()->addMinutes(30);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    /**
     * Events of the same order never run concurrently. Together with the
     * createdAt check in the state machine, this is what makes out-of-order
     * delivery safe.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        $orderId = IfoodEvent::query()->whereKey($this->ifoodEventId)->value('order_id');

        return [
            (new WithoutOverlapping('ifood-order:'.(is_string($orderId) ? $orderId : $this->ifoodEventId)))
                ->releaseAfter(2)
                ->expireAfter(120),
        ];
    }

    public function handle(EventProcessor $processor): void
    {
        try {
            DB::transaction(function () use ($processor): void {
                $event = IfoodEvent::query()->lockForUpdate()->find($this->ifoodEventId);

                if ($event === null || $event->status->isFinal()) {
                    return;
                }

                $processor->process($event);
            });
        } catch (IfoodRateLimitedException $e) {
            // Not the event's fault: wait for the reset without spending an attempt.
            $this->release($e->retryAfter);
        } catch (OrderNotYetKnownException) {
            // A CAR/CARF overtook the order's first status event: wait for it.
            $this->release(10);
        } catch (Throwable $e) {
            IfoodEvent::query()->find($this->ifoodEventId)?->recordFailedAttempt($e);

            Log::channel('ifood')->warning('ifood.event.attempt_failed', [
                'ifood_event_id' => $this->ifoodEventId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        $event = IfoodEvent::query()->find($this->ifoodEventId);

        if ($event === null || $event->status->isFinal()) {
            return;
        }

        $event->markFailed($this->maxExceptions);

        Log::channel('ifood')->error('ifood.event.failed', [
            'ifood_event_id' => $event->id,
            'event_id' => $event->event_id,
            'code' => $event->code,
            'attempts' => $event->attempts,
            'next_retry_at' => $event->next_retry_at?->toIso8601String(),
            'error' => $e?->getMessage(),
        ]);
    }
}
