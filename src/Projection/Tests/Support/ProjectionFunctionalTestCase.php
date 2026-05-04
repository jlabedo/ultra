<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Bundle\MapperBundle;
use Ultra\Projection\Bundle\ProjectionBundle;
use Ultra\Projection\Bundle\ProjectionImplementationInterface;
use Ultra\Projection\Coordination\ProjectionActivatorInterface;
use Ultra\Projection\Coordination\ProjectionBucketActivityRepositoryInterface;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\Coordination\ProjectionCoordinatorLeaseRepositoryInterface;
use Ultra\Projection\InMemory\InMemoryProjectionImplementation;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Testing\UltraLight;
use Ultra\Testing\UltraLightContainerFactoryInterface;

abstract class ProjectionFunctionalTestCase extends TestCase
{
    /**
     * @return UltraLight<ProjectionTestContainer>
     */
    protected function projectionUltraLight(
        ?EventStore $eventStore = null,
        ?ProjectionImplementationInterface $implementation = null,
    ): UltraLight {
        /** @var UltraLightContainerFactoryInterface<ProjectionTestContainer> $containerFactory */
        $containerFactory = new class implements UltraLightContainerFactoryInterface {
            public function create(Container $container): ProjectionTestContainer
            {
                return new ProjectionTestContainer($container);
            }
        };

        return new UltraLight()
            ->withBuildDir(dirname(__DIR__, 4).'/var/projection_tests')
            ->withContainerFactory($containerFactory)
            ->withBundle(new MapperBundle())
            ->withBundle(new ProjectionBundle())
            ->withService($eventStore ?? new InMemoryEventStore(), EventStore::class)
            ->withService($implementation ?? new InMemoryProjectionImplementation(), ProjectionImplementationInterface::class)
            ->withAlias('test.event_store', EventStore::class)
            ->withAlias('test.normalizer', UltraNormalizer::class)
            ->withAlias('test.projection_catalog', ProjectionCatalog::class)
            ->withAlias('test.projection_runner', ProjectionRunner::class)
            ->withAlias('test.projection_coordinator', ProjectionCoordinator::class)
            ->withAlias('test.projection_segments', ProjectionSegmentRepositoryInterface::class)
            ->withAlias('test.projection_activity', ProjectionBucketActivityRepositoryInterface::class)
            ->withAlias('test.projection_leases', ProjectionCoordinatorLeaseRepositoryInterface::class)
            ->withAlias('test.projection_activator', ProjectionActivatorInterface::class)
        ;
    }
}
