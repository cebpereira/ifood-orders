<?php

declare(strict_types=1);

namespace App\Ifood\Enums;

enum OrderStatus: string
{
    case Placed = 'PLACED';
    case Confirmed = 'CONFIRMED';
    case PreparationStarted = 'PREPARATION_STARTED';
    case ReadyToPickup = 'READY_TO_PICKUP';
    case Dispatched = 'DISPATCHED';
    case Concluded = 'CONCLUDED';
    case Cancelled = 'CANCELLED';

    /** Status set by an event code, or null for codes that do not change the status (CAR, CARF). */
    public static function fromEventCode(EventCode $code): ?self
    {
        return match ($code) {
            EventCode::Placed => self::Placed,
            EventCode::Confirmed => self::Confirmed,
            EventCode::PreparationStarted => self::PreparationStarted,
            EventCode::ReadyToPickup => self::ReadyToPickup,
            EventCode::Dispatched => self::Dispatched,
            EventCode::Concluded => self::Concluded,
            EventCode::Cancelled => self::Cancelled,
            EventCode::CancellationRequested, EventCode::CancellationRequestFailed => null,
        };
    }

    /** Statuses only move forward. CANCELLED is handled apart: it can follow any non-terminal status. */
    public function rank(): int
    {
        return match ($this) {
            self::Placed => 1,
            self::Confirmed => 2,
            self::PreparationStarted => 3,
            self::ReadyToPickup => 4,
            self::Dispatched => 5,
            self::Concluded, self::Cancelled => 6,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Concluded || $this === self::Cancelled;
    }

    public function label(): string
    {
        return match ($this) {
            self::Placed => 'Novo',
            self::Confirmed => 'Confirmado',
            self::PreparationStarted => 'Em preparo',
            self::ReadyToPickup => 'Pronto',
            self::Dispatched => 'Despachado',
            self::Concluded => 'Concluído',
            self::Cancelled => 'Cancelado',
        };
    }
}
