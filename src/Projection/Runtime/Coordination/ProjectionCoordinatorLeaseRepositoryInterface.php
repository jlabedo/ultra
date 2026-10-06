<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\Time\Duration;

interface ProjectionCoordinatorLeaseRepositoryInterface
{
    public function acquire(string $workerId, Duration $leaseDuration = new Duration(seconds: 60)): bool;

    public function renew(string $workerId, Duration $leaseDuration = new Duration(seconds: 60)): void;

    public function release(string $workerId): void;

    public function hasLiveCoordinator(): bool;

    public function markWakeEnqueued(Duration $ttl = new Duration(seconds: 30)): bool;
}
