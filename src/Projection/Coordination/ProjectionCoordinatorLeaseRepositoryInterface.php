<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

interface ProjectionCoordinatorLeaseRepositoryInterface
{
    public function acquire(string $projectionId, string $workerId, int $leaseDurationSeconds = 60): bool;

    public function renew(string $projectionId, string $workerId, int $leaseDurationSeconds = 60): void;

    public function release(string $projectionId, string $workerId): void;

    public function hasLiveCoordinator(string $projectionId): bool;

    public function markWakeEnqueued(string $projectionId, int $ttlSeconds = 30): bool;
}
