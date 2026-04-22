<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

interface ProjectionCoordinatorLeaseRepositoryInterface
{
    public function acquire(string $workerId, int $leaseDurationSeconds = 60): bool;

    public function renew(string $workerId, int $leaseDurationSeconds = 60): void;

    public function release(string $workerId): void;

    public function hasLiveCoordinator(): bool;

    public function markWakeEnqueued(int $ttlSeconds = 30): bool;
}
