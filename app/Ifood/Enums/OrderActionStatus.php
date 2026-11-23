<?php

declare(strict_types=1);

namespace App\Ifood\Enums;

enum OrderActionStatus: string
{
    /** Saved, the job has not called iFood yet. */
    case Pending = 'pending';

    /** iFood answered 202; waiting for the event that confirms it. */
    case Sent = 'sent';

    /** The matching event arrived. */
    case Confirmed = 'confirmed';

    /** iFood rejected it (4xx, CARF) or every attempt failed. */
    case Failed = 'failed';

    /** Accepted with 202 but no event came: iFood drops invalid actions silently. */
    case Expired = 'expired';

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Sent;
    }
}
