<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// iFood expects polling every 30s; it also keeps the store marked as online.
Schedule::command('ifood:poll')
    ->everyThirtySeconds()
    ->withoutOverlapping(2)
    ->onOneServer();

Schedule::command('ifood:retry')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
