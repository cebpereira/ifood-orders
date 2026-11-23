<?php

declare(strict_types=1);

namespace App\Ifood\Actions;

use App\Ifood\Enums\OrderActionStatus;
use App\Ifood\Enums\OrderActionType;
use App\Ifood\Enums\OrderStatus;
use App\Jobs\SendOrderActionJob;
use App\Models\IfoodEvent;
use App\Models\Order;
use App\Models\OrderAction;
use App\Support\CorrelationId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class OrderActionService
{
    /**
     * Record the action and queue the call to iFood. The order row is locked
     * so the check and the insert cannot race with an incoming event.
     *
     * @throws ActionNotAllowedException
     */
    public function request(Order $order, OrderActionType $type, ?string $cancellationCode = null, ?string $reason = null): OrderAction
    {
        try {
            return DB::transaction(function () use ($order, $type, $cancellationCode, $reason): OrderAction {
                $order = Order::query()->lockForUpdate()->findOrFail($order->id);

                if (! $type->allowedFor($order->status, $order->order_type)) {
                    throw new ActionNotAllowedException("Não é possível \"{$type->label()}\" com o pedido nesta etapa.");
                }

                if ($type === OrderActionType::RequestCancellation && ($cancellationCode === null || $cancellationCode === '')) {
                    throw new ActionNotAllowedException('Escolha o motivo do cancelamento.');
                }

                $action = OrderAction::query()->create([
                    'order_id' => $order->id,
                    'action' => $type,
                    'status' => OrderActionStatus::Pending,
                    'cancellation_code' => $cancellationCode,
                    'cancellation_reason' => $reason,
                    'requested_at' => now(),
                    'correlation_id' => CorrelationId::get(),
                ]);

                SendOrderActionJob::dispatch($action->id)->afterCommit();

                return $action;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ActionNotAllowedException('Já existe uma ação aguardando resposta do iFood para este pedido.');
        }
    }

    /**
     * Called for every processed event of an order (inside the event job's
     * transaction): closes the open action the event answers.
     */
    public function resolveFromEvent(Order $order, IfoodEvent $event, bool $statusApplied): void
    {
        $action = OrderAction::query()->where('order_id', $order->id)->open()->lockForUpdate()->first();

        if ($action === null) {
            return;
        }

        $outcome = match (true) {
            // CARF: iFood refused the cancellation (or the action) explicitly.
            $event->code === 'CARF' => [OrderActionStatus::Failed, self::carfMessage($event)],
            // CAR alone does not settle a cancellation request: a CARF may still follow.
            // The CAN does, through the rule below.
            // The order reached (or passed) the status the action aimed for.
            $statusApplied && $order->status->rank() >= $action->action->targetStatus()->rank()
                && ($order->status !== OrderStatus::Cancelled || $action->action === OrderActionType::RequestCancellation) => [OrderActionStatus::Confirmed, null],
            // Cancelled while another action was open: that action can no longer happen.
            $statusApplied && $order->status === OrderStatus::Cancelled => [OrderActionStatus::Failed, 'O pedido foi cancelado antes da confirmação'],
            default => null,
        };

        if ($outcome === null) {
            return;
        }

        $action->resolve($outcome[0], $event->id, $outcome[1]);

        Log::channel('ifood')->info('ifood.action.resolved', [
            'order_id' => $order->ifood_order_id,
            'action' => $action->action->value,
            'status' => $outcome[0]->value,
            'event' => $event->code,
        ]);
    }

    /** Actions accepted with 202 whose event never came. */
    public function expireOverdue(): int
    {
        $expired = 0;

        OrderAction::query()
            ->where('status', OrderActionStatus::Sent)
            ->where('expires_at', '<=', now())
            ->each(function (OrderAction $action) use (&$expired): void {
                $action->resolve(OrderActionStatus::Expired, error: 'O iFood aceitou a ação, mas não confirmou com um evento');
                $expired++;

                Log::channel('ifood')->warning('ifood.action.expired', [
                    'order_action_id' => $action->id,
                    'action' => $action->action->value,
                ]);
            });

        return $expired;
    }

    private static function carfMessage(IfoodEvent $event): string
    {
        $details = data_get($event->metadata, 'details') ?? data_get($event->metadata, 'reason');

        return is_string($details) && $details !== '' ? "Recusado pelo iFood: {$details}" : 'Recusado pelo iFood';
    }
}
