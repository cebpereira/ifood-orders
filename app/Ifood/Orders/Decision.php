<?php

declare(strict_types=1);

namespace App\Ifood\Orders;

use App\Ifood\Enums\OrderStatus;

final readonly class Decision
{
    public const string NOT_FORWARD = 'not_forward';

    public const string ORDER_TERMINAL = 'order_terminal';

    public const string NO_STATUS_CHANGE = 'no_status_change';

    private function __construct(
        public bool $applies,
        public ?OrderStatus $to,
        public ?string $reason,
    ) {}

    public static function apply(OrderStatus $to): self
    {
        return new self(true, $to, null);
    }

    public static function ignore(string $reason, ?OrderStatus $to = null): self
    {
        return new self(false, $to, $reason);
    }

    /** Ignored transitions that are still a normal outcome for the event (CAR, CARF). */
    public function isInformational(): bool
    {
        return $this->reason === self::NO_STATUS_CHANGE;
    }
}
