<?php

declare(strict_types=1);

namespace Ultra\Projection\InMemory;

use Ultra\Projection\Bundle\ProjectionImplementationInterface;
use Ultra\Projection\NoOpProjectionTransactionManager;
use Ultra\Projection\ProjectionSegmentStoreInterface;
use Ultra\Projection\ProjectionTransactionManagerInterface;

final class InMemoryProjectionImplementation implements ProjectionImplementationInterface
{
    public function createSegmentStore(): ProjectionSegmentStoreInterface
    {
        return new InMemoryProjectionSegmentStore();
    }

    public function createTransactionManager(): ProjectionTransactionManagerInterface
    {
        return new NoOpProjectionTransactionManager();
    }
}
