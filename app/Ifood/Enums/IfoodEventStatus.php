<?php

declare(strict_types=1);

namespace App\Ifood\Enums;

enum IfoodEventStatus: string
{
    /** Stored from polling, waiting for (or between) processing attempts. */
    case Received = 'received';

    /** Applied to the order. */
    case Processed = 'processed';

    /** Deliberately not applied (unknown code, late event…), see ignore_reason. */
    case Ignored = 'ignored';

    /** Every attempt failed; ifood:retry picks it up again after next_retry_at. */
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Processed || $this === self::Ignored;
    }
}
