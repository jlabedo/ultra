<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MappedTable\Symfony\MappedTableExtension;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Bundle\TagNames;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionDefinition;
use Ultra\Projection\ProjectionHandlerDefinition;
use Ultra\Projection\ProjectionRuntime;
use Ultra\UnitOfWork\UnitOfWork;

final class ProjectionCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $runtimes = [];
        $owners = [];
        $projectionRowSets = [];

        foreach (array_keys($container->findTaggedServiceIds(TagNames::PROJECTION)) as $serviceId) {
            $class = $container->findDefinition($serviceId)->getClass();
            if (null === $class || !class_exists($class)) {
                throw new \LogicException('Projection services must declare a class.');
            }
            $reflection = new \ReflectionClass($class);
            $attributes = $reflection->getAttributes(Projection::class);
            if ([] === $attributes) {
                throw new \LogicException('Projection services must declare #[Projection].');
            }
            $attribute = $attributes[0]->newInstance();
            $participants = $attribute->unitOfWork;
            if (is_a($class, UnitOfWork::class, true)) {
                $participants[] = $serviceId;
            }
            $projectionRowSets[$serviceId] = $attribute->rowSets;
            $references = [];
            if ([] !== $attribute->rowSets) {
                if (!class_exists(MappedTableExtension::class)) {
                    throw new \LogicException('Projection row sets require MappedTableExtension.');
                }
                $references[] = MappedTableExtension::privateUnitOfWork($container, $serviceId, $attribute->rowSets);
            }
            foreach (array_unique($participants) as $participant) {
                $definition = $container->findDefinition($participant);
                $identity = spl_object_id($definition);
                if (isset($owners[$identity]) && $owners[$identity] !== $serviceId) {
                    throw new \LogicException('Projection persistence participants must have a single owner.');
                }
                $owners[$identity] = $serviceId;
                $definition->clearTag(\Ultra\UnitOfWork\TagNames::UNIT_OF_WORK);
                $references[] = new Reference($participant);
            }

            $runtime = new Definition(ProjectionRuntime::class, [
                new Reference($serviceId),
                new Reference(UltraNormalizer::class),
                $this->compileMetadata(ProjectionDefinition::fromClass($class)),
                $references,
            ]);

            $runtimeId = 'ultra.projection.runtime.' . $serviceId;
            $container->setDefinition($runtimeId, $runtime);

            $runtimes[] = new Reference($runtimeId);
        }

        $container->setParameter(ProjectionPersistenceCompilerPass::ROW_SETS, $projectionRowSets);
        $container->register(ProjectionCatalog::class, ProjectionCatalog::class)->setArguments($runtimes);
    }

    private function compileMetadata(ProjectionDefinition $projection): Definition
    {
        $handlers = [];
        foreach ($projection->handledEventTypes() as $eventType) {
            $handler = $projection->handlerFor($eventType);
            assert(null !== $handler, 'A declared projection event type must have handler metadata.');
            $handlers[$eventType] = new Definition(ProjectionHandlerDefinition::class, [
                $handler->eventType,
                $handler->eventClass,
                $handler->method,
            ]);
        }

        return new Definition(ProjectionDefinition::class, [
            $projection->projectionId,
            $projection->partitionDimension,
            $projection->mode,
            $projection->initializerMethod,
            $projection->resetterMethod,
            $projection->flushMethod,
            $handlers,
            $projection->fingerprint,
        ]);
    }
}
