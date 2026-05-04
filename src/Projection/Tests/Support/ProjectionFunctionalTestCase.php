<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;
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
            ->withBundle(new class ($eventStore ?? new InMemoryEventStore()) extends Bundle {
                public function __construct(private EventStore $eventStore){}
                public function build(ContainerBuilder $container): void
                {
                    $container->register(ProjectionTestEventStoreFactory::class)
                        ->setArguments([$this->eventStore])
                    ;
                    $container->register(EventStore::class, $this->eventStore::class)
                        ->setFactory([new Reference(ProjectionTestEventStoreFactory::class), 'create'])
                    ;
                }
            })
            ->withBundle(new MapperBundle())
            ->withBundle(new ProjectionBundle())
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

final readonly class ProjectionTestEventStoreFactory
{
    public function __construct(
        private EventStore $eventStore,
    ) {}

    public function create(): EventStore
    {
        return $this->eventStore;
    }
}