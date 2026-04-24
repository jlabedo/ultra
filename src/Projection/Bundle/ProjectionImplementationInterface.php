<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle;

use Ultra\Projection\Coordination\ProjectionBucketActivityRepositoryInterface;
use Ultra\Projection\Coordination\ProjectionCoordinatorLeaseRepositoryInterface;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Projection\ProjectionTransactionManagerInterface;

interface ProjectionImplementationInterface
{
    public function createSegmentRepository(): ProjectionSegmentRepositoryInterface;

    public function createBucketActivityRepository(): ProjectionBucketActivityRepositoryInterface;

    public function createCoordinatorLeaseRepository(): ProjectionCoordinatorLeaseRepositoryInterface;

    public function createTransactionManager(): ProjectionTransactionManagerInterface;
}
