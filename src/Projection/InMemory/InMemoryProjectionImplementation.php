<?php

declare(strict_types=1);

namespace Ultra\Projection\InMemory;

use Ultra\Projection\Bundle\ProjectionImplementationInterface;
use Ultra\Projection\Coordination\ProjectionBucketActivityRepositoryInterface;
use Ultra\Projection\Coordination\ProjectionCoordinatorLeaseRepositoryInterface;
use Ultra\Projection\NoOpProjectionTransactionManager;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Projection\ProjectionTransactionManagerInterface;

final class InMemoryProjectionImplementation implements ProjectionImplementationInterface
{
    public function createSegmentRepository(): ProjectionSegmentRepositoryInterface
    {
        return new InMemoryProjectionSegmentRepository();
    }

    public function createBucketActivityRepository(): ProjectionBucketActivityRepositoryInterface
    {
        return new InMemoryProjectionBucketActivityRepository();
    }

    public function createCoordinatorLeaseRepository(): ProjectionCoordinatorLeaseRepositoryInterface
    {
        return new InMemoryProjectionCoordinatorLeaseRepository();
    }

    public function createTransactionManager(): ProjectionTransactionManagerInterface
    {
        return new NoOpProjectionTransactionManager();
    }
}
