<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Container\ContainerExtension;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Bundle\MapperExtension;
use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Projection\Bundle\ProjectionExtension;
use Ultra\Projection\Bundle\ProjectionImplementationInterface;
use Ultra\Projection\Coordination\ProjectionActivatorInterface;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\InMemory\InMemoryProjectionImplementation;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Projection\Bundle\TagNames;
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
            ->withBundle(new class ($eventStore ?? new InMemoryEventStore()) implements ContainerExtension {
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
            ->withBundle(new MapperExtension())
            ->withBundle(new ProjectionExtension())
            ->withService($implementation ?? new InMemoryProjectionImplementation(), ProjectionImplementationInterface::class)
            ->withAlias('test.event_store', EventStore::class)
            ->withAlias('test.normalizer', UltraNormalizer::class)
            ->withAlias('test.projection_catalog', ProjectionCatalog::class)
            ->withAlias('test.projection_runner', ProjectionRunner::class)
            ->withAlias('test.projection_coordinator', ProjectionCoordinator::class)
            ->withAlias('test.projection_segments', ProjectionSegmentRepositoryInterface::class)
            ->withAlias('test.projection_activity', ProjectionActivity::class)
            ->withAlias('test.projection_leases', ProjectionExtension::COORDINATOR_LEASE_ID)
            ->withAlias('test.projection_activator', ProjectionActivatorInterface::class)
            ->withExtension(new class implements ContainerExtension {
                public function build(ContainerBuilder $container): void
                {
                    $container->register('test.projection_signal_handlers', ProjectionSignalHandlerCollection::class)
                        ->setArguments([new TaggedIteratorArgument(TagNames::SIGNAL_HANDLER)])
                        ->setPublic(true)
                    ;
                }
            })
        ;
    }
}

final readonly class ProjectionSignalHandlerCollection
{
    /**
     * @param iterable<\Ultra\Signal\SignalHandler> $handlers
     */
    public function __construct(private iterable $handlers) {}

    /**
     * @return list<\Ultra\Signal\SignalHandler>
     */
    public function all(): array
    {
        return array_values(is_array($this->handlers) ? $this->handlers : iterator_to_array($this->handlers, false));
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
