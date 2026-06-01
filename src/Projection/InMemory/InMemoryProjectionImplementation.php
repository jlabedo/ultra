<?php

declare(strict_types=1);

namespace Ultra\Projection\InMemory;

use Ultra\Coordination\InMemory\InMemoryCoordinatorLease;
use Ultra\Coordination\InMemory\InMemoryDirtyKeyTracker;
use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Coordination\Tracking\DirtyKeyTracker;
use Ultra\Projection\Bundle\ProjectionImplementationInterface;
use Ultra\Projection\NoOpProjectionTransactionManager;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Projection\ProjectionTransactionManagerInterface;

final class InMemoryProjectionImplementation implements ProjectionImplementationInterface
{
    private ?DirtyKeyTracker $tracker = null;
    private ?CoordinatorLease $lease = null;

    public function createSegmentRepository(): ProjectionSegmentRepositoryInterface
    {
        return new InMemoryProjectionSegmentRepository();
    }

    public function createDirtyKeyTracker(): DirtyKeyTracker
    {
        return $this->tracker ??= new InMemoryDirtyKeyTracker();
    }

    public function createCoordinatorLease(): CoordinatorLease
    {
        return $this->lease ??= new InMemoryCoordinatorLease();
    }

    public function createTransactionManager(): ProjectionTransactionManagerInterface
    {
        return new NoOpProjectionTransactionManager();
    }
}
