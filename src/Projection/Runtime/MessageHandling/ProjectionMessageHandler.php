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

        for ($cycle = 0; $cycle < $this->maxCycles; ++$cycle) {
            $result = $this->runner->runClaimedOnce(
                $runtime,
                $message->projectionId,
                $message->bucketFrom,
                $message->workerId,
                $this->batchSize,
            );
            if (!$result->claimedSegment || $result->caughtUp) {
                return;
            }
        }
    }
}
