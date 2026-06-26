<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle;

use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Projection\ProjectionTransactionManagerInterface;

interface ProjectionImplementationInterface
{
    public function createSegmentRepository(): ProjectionSegmentRepositoryInterface;

    public function createTransactionManager(): ProjectionTransactionManagerInterface;
}
