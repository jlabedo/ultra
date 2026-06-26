<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Container\ContainerExtension;
use Ultra\EventStore\EventStore;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Bundle\Command\ListSegmentsCommand;
use Ultra\Projection\Bundle\DependencyInjection\Compiler\ProjectionCompilerPass;
use Ultra\Projection\Bundle\DependencyInjection\Compiler\ProjectionSignalHandlerCompilerPass;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
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

        $container->register(ProjectionSegmentRepositoryInterface::class)
            ->setFactory([$factory, 'createSegmentRepository'])
        ;

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
                new Reference(ProjectionSegmentRepositoryInterface::class),
                new Reference(ProjectionTransactionManagerInterface::class),
            ])
        ;

        $container->register(ListSegmentsCommand::class, ListSegmentsCommand::class)
            ->setArguments([new Reference(ProjectionSegmentRepositoryInterface::class)])
            ->addTag('console.command')
        ;

        $container->addCompilerPass(new ProjectionCompilerPass());
        $container->addCompilerPass(new ProjectionSignalHandlerCompilerPass());
    }
}
