<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\Events\EventRegistryInterface;
use Ultra\EventSourcedState\EventSourcedStateStore;
use Ultra\EventStore\EventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\TagNames as MessageHandlingTagNames;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateStore;

final class DecisionEngineBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if ($container->has(EventSourcedStateStore::class)) {
                    $container->setDefinition(
                        EventSourcedDecisionAspect::class,
                        (new Definition(EventSourcedDecisionAspect::class, [
                            new Reference(EventSourcedStateStore::class),
                            new Reference(EventStore::class),
                            new Reference(EventRegistryInterface::class),
                            new Reference(UltraNormalizer::class),
                            new Reference(EventBusInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
                        ]))->addTag(MessageHandlingTagNames::ASPECT),
                    );
                }

                if ($container->has(StateStore::class)) {
                    $container->setDefinition(
                        StateStoreDecisionAspect::class,
                        (new Definition(StateStoreDecisionAspect::class, [
                            new Reference(StateStore::class),
                            new Reference(StateMetadataRegistry::class),
                        ]))->addTag(MessageHandlingTagNames::ASPECT),
                    );
                }
            }
        }, priority: 40);
    }
}
