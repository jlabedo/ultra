<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
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
        $providers = [];

        foreach (array_keys($container->findTaggedServiceIds(TagNames::PROJECTION)) as $serviceId) {
            $projection = $container->findDefinition($serviceId);
            $class = $projection->getClass();
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
            foreach ($projection->getTag(TagNames::PERSISTENCE) as $tag) {
                if (!is_string($tag['service'] ?? null)) {
                    throw new \LogicException(sprintf('Projection "%s" must name a persistence service.', $serviceId));
                }
                $participants[] = $tag['service'];
            }
            if (is_a($class, UnitOfWork::class, true)) {
                $participants[] = $serviceId;
            }
            $references = [];
            foreach (array_unique($participants) as $participant) {
                $definition = $container->findDefinition($participant);
                if (!$definition->isShared()) {
                    throw new \LogicException(sprintf('Projection persistence participant "%s" must be shared.', $participant));
                }
                foreach ($definition->getTag(\Ultra\UnitOfWork\TagNames::DBAL_CONNECTION_PROVIDER) as $provider) {
                    if (!is_array($provider) || !is_string($provider['service'] ?? null)) {
                        throw new \LogicException(sprintf('Participant "%s" must name its DBAL connection provider.', $participant));
                    }
                    $providers[] = $provider['service'];
                }
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

        $container->setParameter(ProjectionPersistenceCompilerPass::CONNECTION_PROVIDERS, array_unique($providers));
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
