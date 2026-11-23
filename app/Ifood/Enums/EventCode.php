<?php

declare(strict_types=1);

namespace App\Ifood\Enums;

/**
 * Order event codes this project understands. iFood sends others too (DDCR was
 * seen in discovery): those are stored and acked, then ignored.
 */
enum EventCode: string
{
    case Placed = 'PLC';
    case Confirmed = 'CFM';
    case PreparationStarted = 'PRS';
    case ReadyToPickup = 'RTP';
    case Dispatched = 'DSP';
    case Concluded = 'CON';
    case Cancelled = 'CAN';
    case CancellationRequested = 'CAR';
    case CancellationRequestFailed = 'CARF';
}
