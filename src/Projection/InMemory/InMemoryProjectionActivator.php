<?php

declare(strict_types=1);

namespace Ultra\Projection\InMemory;

use Ultra\Projection\Coordination\ProjectionActivatorInterface;

final class InMemoryProjectionActivator implements ProjectionActivatorInterface
{
    private int $workSignals = 0;

    /** @var list<array{projectionId: string, bucketFrom: int, workerId: string}> */
    private array $workerClaims = [];

    public function signalWorkAvailable(): void
    {
        ++$this->workSignals;
    }

    public function activateWorker(string $projectionId, int $bucketFrom, string $workerId): void
    {
        $this->workerClaims[] = [
            'projectionId' => $projectionId,
            'bucketFrom' => $bucketFrom,
            'workerId' => $workerId,
        ];
    }

    public function workSignals(): int
    {
        return $this->workSignals;
    }

    /** @return list<array{projectionId: string, bucketFrom: int, workerId: string}> */
    public function workerClaims(): array
    {
        return $this->workerClaims;
    }
}
