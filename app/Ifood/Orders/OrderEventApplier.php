<?php

declare(strict_types=1);

namespace App\Ifood\Orders;

use App\Ifood\Enums\EventCode;
use App\Ifood\Enums\OrderStatus;
use App\Ifood\Exceptions\OrderNotYetKnownException;
use App\Jobs\FetchOrderDetailsJob;
use App\Models\IfoodEvent;
use App\Models\Order;
use App\Models\OrderTransition;
use Illuminate\Support\Facades\Log;

/**
 * Applies one event to its order. Runs inside the event job's transaction,
 * with the order row locked, and records the outcome in order_transitions
 * whether or not the status changed.
 */
final class OrderEventApplier
{
    public function __construct(private readonly OrderStateMachine $machine) {}

    public function apply(IfoodEvent $event, EventCode $code): Decision
    {
        $order = Order::query()
            ->where('ifood_order_id', $event->order_id)
            ->lockForUpdate()
            ->first();

        $decision = $this->machine->decide($order?->status, $code);

        if ($order === null) {
            if (! $decision->applies || $decision->to === null) {
                throw new OrderNotYetKnownException("Order {$event->order_id} unknown for {$event->code}");
            }

            $order = $this->createOrder($event, $decision->to);
            $from = null;
        } else {
            $from = $order->status;

            if ($decision->applies && $decision->to !== null) {
                $order->status = $decision->to;
                $order->status_changed_at = $event->event_created_at;
            }
        }

        $this->recordFacts($order, $event, $code, $decision);
        $order->save();

        OrderTransition::query()->updateOrCreate(
            ['ifood_event_id' => $event->id],
            [
                'order_id' => $order->id,
                'event_code' => $event->code,
                'from_status' => $from,
                'to_status' => $decision->to,
                'applied' => $decision->applies,
                'reason' => $decision->reason,
                'occurred_at' => $event->event_created_at,
            ],
        );

        if (! $decision->applies && ! $decision->isInformational()) {
            Log::channel('ifood')->notice('ifood.order.transition_ignored', [
                'order_id' => $order->ifood_order_id,
                'event_id' => $event->event_id,
                'code' => $event->code,
                'current_status' => $from?->value,
                'reason' => $decision->reason,
            ]);
        }

        return $decision;
    }

    private function createOrder(IfoodEvent $event, OrderStatus $status): Order
    {
        $order = Order::query()->create([
            'ifood_order_id' => $event->order_id,
            'merchant_id' => $event->merchant_id,
            'status' => $status,
            'first_event_at' => $event->event_created_at,
            'status_changed_at' => $event->event_created_at,
            'sales_channel' => $event->sales_channel,
        ]);

        // Details may 404 right after PLC; the job retries with backoff.
        FetchOrderDetailsJob::dispatch($order->id)->afterCommit();

        return $order;
    }

    /**
     * Facts that hold regardless of the status decision: a late PLC still
     * tells us when the order was placed, a CAR after CAN still tells us a
     * cancellation was asked for.
     */
    private function recordFacts(Order $order, IfoodEvent $event, EventCode $code, Decision $decision): void
    {
        $at = $event->event_created_at;

        if ($at->lessThan($order->first_event_at)) {
            $order->first_event_at = $at;
        }

        if ($code === EventCode::Placed && ($order->placed_at === null || $at->lessThan($order->placed_at))) {
            $order->placed_at = $at;
            $order->confirm_deadline_at = $at->addMinutes(Order::CONFIRM_DEADLINE_MINUTES);
        }

        $metadata = $event->metadata ?? [];

        if ($code === EventCode::CancellationRequested) {
            $order->cancellation_requested_at ??= $at;
            $order->cancellation_code ??= self::str($metadata['reason_code'] ?? null);
            $order->cancellation_reason ??= self::str($metadata['details'] ?? null);
        }

        // Only the CAN that actually cancelled the order describes the cancellation.
        if ($code === EventCode::Cancelled && $decision->applies) {
            $order->cancellation_code = self::str($metadata['CANCEL_CODE'] ?? null) ?? $order->cancellation_code;
            $order->cancellation_reason = self::str($metadata['CANCEL_CODE_DESCRIPTION'] ?? $metadata['CANCEL_REASON'] ?? null)
                ?? $order->cancellation_reason;
            $order->cancellation_origin = self::str($metadata['CANCEL_ORIGIN'] ?? null);
        }
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
