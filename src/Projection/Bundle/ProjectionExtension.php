<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Container\ContainerExtension;
use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Coordination\Tracking\DirtyKeyTracker;
use Ultra\EventStore\EventStore;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Bundle\Command\ListSegmentsCommand;
use Ultra\Projection\Bundle\Command\ProjectionWorkerCommand;
use Ultra\Projection\Bundle\DependencyInjection\Compiler\MessageHandlingProjectionActivatorPass;
use Ultra\Projection\Bundle\DependencyInjection\Compiler\ProjectionCompilerPass;
use Ultra\Projection\Coordination\DefaultProjectionScalingPolicy;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Projection\Coordination\ProjectionActivityEventStore;
use Ultra\Projection\Coordination\ProjectionActivityRecorder;
use Ultra\Projection\Coordination\ProjectionActivatorInterface;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\Coordination\ProjectionScalingPolicyInterface;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\MessageHandling\ProjectionMessageHandler;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Projection\ProjectionTransactionManagerInterface;

final class ProjectionExtension implements ContainerExtension
{
    /** Projection-private service id for the coordinator's dirty-key tracker. */
    public const string DIRTY_KEY_TRACKER_ID = 'ultra.projection.coordination.dirty_key_tracker';

    /** Projection-private service id for the coordinator's lease. */
    public const string COORDINATOR_LEASE_ID = 'ultra.projection.coordination.coordinator_lease';

    public function build(ContainerBuilder $container): void
    {

        $container->registerAttributeForAutoconfiguration(
            Projection::class,
            static function (ChildDefinition $definition, Projection $attribute, \ReflectionClass $reflector): void {
                $definition->addTag(TagNames::PROJECTION);
            }
        );

        $factory = new Reference(ProjectionImplementationInterface::class);

        $container->register(ProjectionSegmentRepositoryInterface::class)
            ->setFactory([$factory, 'createSegmentRepository'])
        ;

        // Registered under projection-private ids (not the generic interface
        // ids) so an application can bind its own DirtyKeyTracker /
        // CoordinatorLease for unrelated derived read models without colliding
        // with the projection coordinator's instances.
        $container->register(self::DIRTY_KEY_TRACKER_ID, DirtyKeyTracker::class)
            ->setFactory([$factory, 'createDirtyKeyTracker'])
        ;

        $container->register(self::COORDINATOR_LEASE_ID, CoordinatorLease::class)
            ->setFactory([$factory, 'createCoordinatorLease'])
        ;

        $container->register(ProjectionActivity::class, ProjectionActivity::class)
            ->setArguments([new Reference(self::DIRTY_KEY_TRACKER_ID)])
        ;

        $container->register(ProjectionTransactionManagerInterface::class)
            ->setFactory([$factory, 'createTransactionManager'])
        ;

        $container->register(ProjectionActivatorInterface::class, InMemoryProjectionActivator::class);
        $container->register(ProjectionScalingPolicyInterface::class, DefaultProjectionScalingPolicy::class);

        $container->register(ProjectionActivityRecorder::class, ProjectionActivityRecorder::class)
            ->setArguments([
                new Reference(ProjectionCatalog::class),
                new Reference(ProjectionActivity::class),
                new Reference(self::COORDINATOR_LEASE_ID),
                new Reference(ProjectionActivatorInterface::class),
            ])
        ;

        $container->register(ProjectionActivityEventStore::class, ProjectionActivityEventStore::class)
            ->setDecoratedService(EventStore::class, null, 100)
            ->setArguments([
                new Reference(ProjectionActivityEventStore::class.'.inner'),
                new Reference(ProjectionActivityRecorder::class),
            ])
        ;

        $container->register(ProjectionRunner::class, ProjectionRunner::class)
            ->setArguments([
                new Reference(EventStore::class),
                new Reference(ProjectionSegmentRepositoryInterface::class),
                new Reference(ProjectionTransactionManagerInterface::class),
                new Reference(ProjectionActivity::class),
            ])
        ;

        $container->register(ProjectionCoordinator::class, ProjectionCoordinator::class)
            ->setArguments([
                new Reference(ProjectionCatalog::class),
                new Reference(ProjectionRunner::class),
                new Reference(ProjectionActivity::class),
                new Reference(self::COORDINATOR_LEASE_ID),
                new Reference(ProjectionActivatorInterface::class),
                new Reference(ProjectionScalingPolicyInterface::class),
            ])
        ;

        $container->register(ProjectionWorkerCommand::class, ProjectionWorkerCommand::class)
            ->setArguments([new Reference(ProjectionCoordinator::class)])
            ->addTag('console.command')
        ;

        $container->register(ListSegmentsCommand::class, ListSegmentsCommand::class)
            ->setArguments([new Reference(ProjectionSegmentRepositoryInterface::class)])
            ->addTag('console.command')
        ;

        $this->registerMessageHandlingIntegration($container);

        $container->addCompilerPass(new ProjectionCompilerPass());
        $container->addCompilerPass(new MessageHandlingProjectionActivatorPass());
    }

    private function registerMessageHandlingIntegration(ContainerBuilder $container): void
    {
        if (!interface_exists('Ultra\MessageHandling\MediatorInterface')) {
            return;
        }

        $container->register(ProjectionMessageHandler::class, ProjectionMessageHandler::class)
            ->setArguments([
                new Reference(ProjectionCatalog::class),
                new Reference(ProjectionRunner::class),
                new Reference(ProjectionCoordinator::class),
            ])
            ->addTag('ultra.message_handler', ['method' => 'handleWakeProjectionCoordinator'])
            ->addTag('ultra.message_handler', ['method' => 'handleRunProjectionSegment'])
        ;
    }
}
