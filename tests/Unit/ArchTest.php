<?php

declare(strict_types=1);

arch('application code uses strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('no debugging leftovers')
    ->preset()->php();

arch('no secrets or env() calls outside config')
    ->expect('App')
    ->not->toUse(['env']);
