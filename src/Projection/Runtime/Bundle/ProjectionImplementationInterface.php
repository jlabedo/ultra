<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle;

use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Coordination\Tracking\DirtyKeyTracker;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Projection\ProjectionTransactionManagerInterface;

interface ProjectionImplementationInterface
{
    public function createSegmentRepository(): ProjectionSegmentRepositoryInterface;

    public function createDirtyKeyTracker(): DirtyKeyTracker;

    public function createCoordinatorLease(): CoordinatorLease;

    public function createTransactionManager(): ProjectionTransactionManagerInterface;
}
