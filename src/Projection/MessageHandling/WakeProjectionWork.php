<?php

declare(strict_types=1);

namespace Ultra\Projection\MessageHandling;

final readonly class WakeProjectionWork
{
    public function __construct(
        public string $workerId,
    ) {}
}
