<?php

declare(strict_types=1);

namespace Ultra\Projection\MessageHandling;

use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\Projection\Coordination\ProjectionCoordinator;

final readonly class ProjectionMessageHandler
{
    public function __construct(
        private ProjectionCoordinator $coordinator,
        private int $batchSize = 100,
        private int $maxCycles = 32,
    ) {}

    #[Handles]
    #[Asynchronous]
    public function handleWakeProjectionWork(WakeProjectionWork $message): void
    {
        $this->coordinator->run($message->workerId, $this->batchSize, $this->maxCycles);
    }
}
