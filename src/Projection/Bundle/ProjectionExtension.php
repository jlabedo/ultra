<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Container\ContainerExtension;
use Ultra\EventStore\EventStore;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Backfill\ProcessProjectionBackfillExecutor;
use Ultra\Projection\Backfill\ProjectionBackfillExecutorInterface;
use Ultra\Projection\Bundle\Command\BackfillProjectionCommand;
use Ultra\Projection\Bundle\Command\BackfillProjectionSegmentCommand;
use Ultra\Projection\Bundle\Command\ListSegmentsCommand;
use Ultra\Projection\Bundle\DependencyInjection\Compiler\ProjectionCompilerPass;
use Ultra\Projection\Bundle\DependencyInjection\Compiler\ProjectionSignalHandlerCompilerPass;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionSegmentStoreInterface;
use Ultra\Projection\ProjectionTopology;
use Ultra\Projection\ProjectionTopologyStoreInterface;
use Ultra\Projection\ProjectionTransactionManagerInterface;
use Ultra\Projection\Signal\ProjectionSignalEventStore;
use Ultra\Signal\SignalStore;

final class ProjectionExtension implements ContainerExtension
{
    public function build(ContainerBuilder $container): void
    {

        if (!$container->hasParameter('ultra.projection.signal_worker_id')) {
            $container->setParameter('ultra.projection.signal_worker_id', 'projection-signal');
        }

        $container->registerAttributeForAutoconfiguration(
            Projection::class,
            static function (ChildDefinition $definition, Projection $attribute, \ReflectionClass $reflector): void {
                $definition->addTag(TagNames::PROJECTION);
            }
        );

        $factory = new Reference(ProjectionImplementationInterface::class);

        $container->register(ProjectionSegmentStoreInterface::class)
            ->setFactory([$factory, 'createSegmentStore'])
        ;
        $container->setAlias(ProjectionTopologyStoreInterface::class, ProjectionSegmentStoreInterface::class);

        $container->register(ProjectionTransactionManagerInterface::class)
            ->setFactory([$factory, 'createTransactionManager'])
        ;

        if (interface_exists(SignalStore::class) && $container->has(SignalStore::class)) {
            $container->register(ProjectionSignalEventStore::class, ProjectionSignalEventStore::class)
                ->setDecoratedService(EventStore::class, null, 100)
                ->setArguments([
                    new Reference(ProjectionSignalEventStore::class.'.inner'),
                    new Reference(ProjectionCatalog::class),
                    new Reference(SignalStore::class),
                ])
            ;
        }

        $container->register(ProjectionRunner::class, ProjectionRunner::class)
            ->setArguments([
                new Reference(EventStore::class),
                new Reference(ProjectionSegmentStoreInterface::class),
                new Reference(ProjectionTransactionManagerInterface::class),
            ])
        ;

        $container->register(ProjectionTopology::class, ProjectionTopology::class)
            ->setArguments([
                new Reference(EventStore::class),
                new Reference(ProjectionSegmentStoreInterface::class),
                new Reference(ProjectionTopologyStoreInterface::class),
                new Reference(ProjectionTransactionManagerInterface::class),
            ])
        ;

        $container->register(ProcessProjectionBackfillExecutor::class, ProcessProjectionBackfillExecutor::class);
        $container->setAlias(ProjectionBackfillExecutorInterface::class, ProcessProjectionBackfillExecutor::class);

        $container->register(BackfillProjectionCommand::class, BackfillProjectionCommand::class)
            ->setArguments([
                new Reference(ProjectionCatalog::class),
                new Reference(ProjectionRunner::class),
                new Reference(ProjectionTopology::class),
                new Reference(ProjectionBackfillExecutorInterface::class),
            ])
            ->addTag('console.command')
        ;

        $container->register(BackfillProjectionSegmentCommand::class, BackfillProjectionSegmentCommand::class)
            ->setArguments([
                new Reference(ProjectionCatalog::class),
                new Reference(ProjectionRunner::class),
            ])
            ->addTag('console.command')
        ;

        $container->register(ListSegmentsCommand::class, ListSegmentsCommand::class)
            ->setArguments([new Reference(ProjectionSegmentStoreInterface::class)])
            ->addTag('console.command')
        ;

        $container->addCompilerPass(new ProjectionCompilerPass());
        $container->addCompilerPass(new ProjectionSignalHandlerCompilerPass());
    }
}
