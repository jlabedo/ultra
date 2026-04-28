<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\EventStore\EventStore;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Bundle\Command\ProjectionWorkerCommand;
use Ultra\Projection\Bundle\DependencyInjection\Compiler\MessageHandlingProjectionActivatorPass;
use Ultra\Projection\Bundle\DependencyInjection\Compiler\ProjectionCompilerPass;
use Ultra\Projection\Coordination\DefaultProjectionScalingPolicy;
use Ultra\Projection\Coordination\ProjectionActivatorInterface;
use Ultra\Projection\Coordination\ProjectionBucketActivityRepositoryInterface;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\Coordination\ProjectionCoordinatorLeaseRepositoryInterface;
use Ultra\Projection\Coordination\ProjectionScalingPolicyInterface;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\MessageHandling\ProjectionMessageHandler;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Projection\ProjectionTransactionManagerInterface;

final class ProjectionBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

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

        $container->register(ProjectionBucketActivityRepositoryInterface::class)
            ->setFactory([$factory, 'createBucketActivityRepository'])
        ;

        $container->register(ProjectionCoordinatorLeaseRepositoryInterface::class)
            ->setFactory([$factory, 'createCoordinatorLeaseRepository'])
        ;

        $container->register(ProjectionTransactionManagerInterface::class)
            ->setFactory([$factory, 'createTransactionManager'])
        ;

        $container->register(ProjectionActivatorInterface::class, InMemoryProjectionActivator::class);
        $container->register(ProjectionScalingPolicyInterface::class, DefaultProjectionScalingPolicy::class);

        $container->register(ProjectionRunner::class, ProjectionRunner::class)
            ->setArguments([
                new Reference(EventStore::class),
                new Reference(ProjectionSegmentRepositoryInterface::class),
                new Reference(ProjectionTransactionManagerInterface::class),
                new Reference(ProjectionBucketActivityRepositoryInterface::class),
            ])
        ;

        $container->register(ProjectionCoordinator::class, ProjectionCoordinator::class)
            ->setArguments([
                new Reference(ProjectionCatalog::class),
                new Reference(ProjectionRunner::class),
                new Reference(ProjectionBucketActivityRepositoryInterface::class),
                new Reference(ProjectionCoordinatorLeaseRepositoryInterface::class),
                new Reference(ProjectionActivatorInterface::class),
                new Reference(ProjectionScalingPolicyInterface::class),
            ])
        ;

        $container->register(ProjectionWorkerCommand::class, ProjectionWorkerCommand::class)
            ->setArguments([new Reference(ProjectionCoordinator::class)])
            ->addTag('console.command')
        ;

        $this->registerMessageHandlingIntegration($container);

        $container->addCompilerPass(new ProjectionCompilerPass());
        $container->addCompilerPass(new MessageHandlingProjectionActivatorPass());
    }

    private function registerMessageHandlingIntegration(ContainerBuilder $container): void
    {
        if (!interface_exists('Ultra\\MessageHandling\\MediatorInterface')) {
            return;
        }

        $container->register(ProjectionMessageHandler::class, ProjectionMessageHandler::class)
            ->setArguments([
                new Reference(ProjectionCatalog::class),
                new Reference(ProjectionRunner::class),
                new Reference(ProjectionCoordinator::class),
            ])
            ->addTag('ultra.message_handler', ['method' => 'handleWakeProjectionCoordinator'])
            ->addTag('ultra.message_handler', ['method' => 'handleRunProjectionAssignment'])
        ;
    }
}
