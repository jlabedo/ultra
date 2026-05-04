<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Support;

use Ultra\EventStore\EventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Coordination\ProjectionActivatorInterface;
use Ultra\Projection\Coordination\ProjectionBucketActivityRepositoryInterface;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\Coordination\ProjectionCoordinatorLeaseRepositoryInterface;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Testing\UltraLightContainer;

final readonly class ProjectionTestContainer extends UltraLightContainer
{
    public function eventStore(): EventStore
    {
        return $this->get('test.event_store');
    }

    public function normalizer(): UltraNormalizer
    {
        return $this->get('test.normalizer');
    }

    public function projectionCatalog(): ProjectionCatalog
    {
        return $this->get('test.projection_catalog');
    }

    public function projectionRunner(): ProjectionRunner
    {
        return $this->get('test.projection_runner');
    }

    public function projectionCoordinator(): ProjectionCoordinator
    {
        return $this->get('test.projection_coordinator');
    }

    public function projectionSegments(): ProjectionSegmentRepositoryInterface
    {
        return $this->get('test.projection_segments');
    }

    public function projectionActivity(): ProjectionBucketActivityRepositoryInterface
    {
        return $this->get('test.projection_activity');
    }

    public function projectionLeases(): ProjectionCoordinatorLeaseRepositoryInterface
    {
        return $this->get('test.projection_leases');
    }

    public function projectionActivator(): ProjectionActivatorInterface
    {
        return $this->get('test.projection_activator');
    }
}
