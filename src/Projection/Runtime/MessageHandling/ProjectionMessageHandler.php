<?php

declare(strict_types=1);

namespace Ultra\Projection\MessageHandling;

use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;

final readonly class ProjectionMessageHandler
{
    public function __construct(
        private ProjectionCatalog $catalog,
        private ProjectionRunner $runner,
        private ProjectionCoordinator $coordinator,
        private int $batchSize = 100,
        private int $maxCycles = 32,
    ) {}

    #[Handles]
    #[Asynchronous]
    public function handleWakeProjectionCoordinator(WakeProjectionCoordinator $message): void
    {
        $this->coordinator->run($message->workerId, $this->batchSize, $this->maxCycles);
    }

    #[Handles]
    #[Asynchronous]
    public function handleRunProjectionSegment(RunProjectionSegment $message): void
    {
        $runtime = $this->catalog->runtime($message->projectionId);
        if ($this->maxCycles < 1) {
            return;
        }

        foreach ($this->runner->runClaimedBatches(
            $runtime,
            $message->projectionId,
            $message->bucketFrom,
            $message->workerId,
            $this->batchSize,
        ) as $cycle => $_result) {
            if (($cycle + 1) === $this->maxCycles) {
                return;
            }
        }
    }
}
