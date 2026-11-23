<?php

declare(strict_types=1);

namespace App\Ifood\Orders;

use App\Ifood\Enums\EventCode;
use App\Ifood\Enums\OrderStatus;

/**
 * Decides whether an event moves an order. Pure: no I/O, so every rule is unit
 * tested in isolation.
 *
 * Statuses are monotonic, so the highest status seen is the truth no matter
 * the arrival order. That is why the decision uses rank, not timestamps:
 * a late PLC or a repeated CFM is simply "not forward", and a CFM after CAN
 * never reopens the order. Skips are valid (iFood accepted DSP straight
 * from CONFIRMED during discovery).
 */
final class OrderStateMachine
{
    public function decide(?OrderStatus $current, EventCode $code): Decision
    {
        $target = OrderStatus::fromEventCode($code);

        if ($current?->isTerminal()) {
            return Decision::ignore(Decision::ORDER_TERMINAL, $target);
        }

        if ($target === null) {
            return Decision::ignore(Decision::NO_STATUS_CHANGE);
        }

        if ($current === null || $target === OrderStatus::Cancelled) {
            return Decision::apply($target);
        }

        return $target->rank() > $current->rank()
            ? Decision::apply($target)
            : Decision::ignore(Decision::NOT_FORWARD, $target);
    }
}
