<?php

declare(strict_types=1);

namespace App\Ifood\Enums;

enum OrderActionType: string
{
    case Confirm = 'confirm';
    case StartPreparation = 'start_preparation';
    case ReadyToPickup = 'ready_to_pickup';
    case Dispatch = 'dispatch';
    case RequestCancellation = 'request_cancellation';

    /** Status the order reaches once iFood confirms the action by event. */
    public function targetStatus(): OrderStatus
    {
        return match ($this) {
            self::Confirm => OrderStatus::Confirmed,
            self::StartPreparation => OrderStatus::PreparationStarted,
            self::ReadyToPickup => OrderStatus::ReadyToPickup,
            self::Dispatch => OrderStatus::Dispatched,
            self::RequestCancellation => OrderStatus::Cancelled,
        };
    }

    /**
     * Whether the action makes sense for an order. iFood itself answers 202
     * to invalid actions and then drops them silently (docs/discovery.md),
     * so this is checked before calling it.
     */
    public function allowedFor(OrderStatus $status, ?string $orderType): bool
    {
        if ($status->isTerminal()) {
            return false;
        }

        return match ($this) {
            self::RequestCancellation => true,
            self::Dispatch => $orderType === 'DELIVERY' && $status->rank() < OrderStatus::Dispatched->rank() && $status !== OrderStatus::Placed,
            default => $this->targetStatus()->rank() === $status->rank() + 1
                || ($this === self::ReadyToPickup && $status === OrderStatus::Confirmed),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Confirm => 'Confirmar pedido',
            self::StartPreparation => 'Iniciar preparo',
            self::ReadyToPickup => 'Marcar como pronto',
            self::Dispatch => 'Despachar',
            self::RequestCancellation => 'Cancelar pedido',
        };
    }
}
