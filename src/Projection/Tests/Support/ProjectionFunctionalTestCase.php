<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Container\ContainerExtension;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Bundle\MapperExtension;
use Ultra\Projection\Bundle\ProjectionExtension;
use Ultra\Projection\Bundle\ProjectionImplementationInterface;
use Ultra\Projection\Coordination\ProjectionActivatorInterface;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\InMemory\InMemoryProjectionImplementation;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Testing\UltraLight;
use Ultra\Testing\UltraLightContainerFactoryInterface;

abstract class ProjectionFunctionalTestCase extends TestCase
{
    /**
     * @param list<ContainerExtension> $extensionsBefore
     * @return UltraLight<ProjectionTestContainer>
     */
    protected function projectionUltraLight(
        ?EventStore $eventStore = null,
        ?ProjectionImplementationInterface $implementation = null,
        array $extensionsBefore = [],
    ): UltraLight {
        /** @var UltraLightContainerFactoryInterface<ProjectionTestContainer> $containerFactory */
        $containerFactory = new class implements UltraLightContainerFactoryInterface {
            public function create(Container $container): ProjectionTestContainer
            {
                return new ProjectionTestContainer($container);
            }
        };

        return new UltraLight()
            ->withBuildDir(dirname(__DIR__, 4) . '/var/projection_tests')
            ->withContainerFactory($containerFactory)
            ->withExtension(...$extensionsBefore)
            ->withBundle(new class($eventStore ?? new InMemoryEventStore()) implements ContainerExtension {
                public function __construct(
                    private EventStore $eventStore,
                ) {}

                public function build(ContainerBuilder $container): void
                {
                    $container->register(ProjectionTestEventStoreFactory::class)->setArguments([$this->eventStore]);
                    $container
                        ->register(EventStore::class, $this->eventStore::class)
                        ->setFactory([new Reference(ProjectionTestEventStoreFactory::class), 'create']);
                }
            })
            ->withBundle(new MapperExtension())
            ->withBundle(new ProjectionExtension())
            ->withService(
                $implementation ?? new InMemoryProjectionImplementation(),
                ProjectionImplementationInterface::class,
            )
            ->withAlias('test.event_store', EventStore::class)
            ->withAlias('test.normalizer', UltraNormalizer::class)
            ->withAlias('test.projection_catalog', ProjectionCatalog::class)
            ->withAlias('test.projection_runner', ProjectionRunner::class)
            ->withAlias('test.projection_coordinator', ProjectionCoordinator::class)
            ->withAlias('test.projection_segments', ProjectionSegmentRepositoryInterface::class)
            ->withAlias('test.projection_activity', ProjectionActivity::class)
            ->withAlias('test.projection_leases', ProjectionExtension::COORDINATOR_LEASE_ID)
            ->withAlias('test.projection_activator', ProjectionActivatorInterface::class);
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
