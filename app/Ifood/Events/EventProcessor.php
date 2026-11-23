<?php

declare(strict_types=1);

namespace App\Ifood\Events;

use App\Ifood\Orders\OrderEventApplier;
use App\Models\IfoodEvent;

/**
 * Applies one stored event. Called by ProcessIfoodEventJob inside a
 * transaction, with the event row locked; it must mark the event as processed
 * or ignored, or throw to have the job retry.
 */
class EventProcessor
{
    public function __construct(private readonly OrderEventApplier $applier) {}

    public function process(IfoodEvent $event): void
    {
        $code = $event->eventCode();

        if ($code === null) {
            // Stored and acked so it never blocks the queue, but not applied.
            $event->markIgnored('unknown_code');

            return;
        }

        $decision = $this->applier->apply($event, $code);

        $decision->applies || $decision->isInformational()
            ? $event->markProcessed()
            : $event->markIgnored((string) $decision->reason);
    }
}
