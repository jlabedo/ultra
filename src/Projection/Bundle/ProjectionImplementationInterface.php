<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle;

use Ultra\Projection\ProjectionSegmentStoreInterface;
use Ultra\Projection\ProjectionTransactionManagerInterface;

interface ProjectionImplementationInterface
{
    public function createSegmentStore(): ProjectionSegmentStoreInterface;

    public function createTransactionManager(): ProjectionTransactionManagerInterface;
}
