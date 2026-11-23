<?php

declare(strict_types=1);

namespace App\Ifood\Events;

final readonly class IngestResult
{
    public function __construct(
        public int $received = 0,
        public int $new = 0,
        public int $duplicates = 0,
        public bool $acknowledged = true,
    ) {}
}
