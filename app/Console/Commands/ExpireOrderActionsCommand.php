<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ifood\Actions\OrderActionService;
use Illuminate\Console\Command;

final class ExpireOrderActionsCommand extends Command
{
    protected $signature = 'ifood:expire-actions';

    protected $description = 'Expire actions iFood accepted (202) but never confirmed with an event';

    public function handle(OrderActionService $actions): int
    {
        $this->info("Expired {$actions->expireOverdue()} action(s).");

        return self::SUCCESS;
    }
}
