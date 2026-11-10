<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Bundle\TagNames;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Attribute\Projection;
use Ultra\UnitOfWork\UnitOfWork;

final class ProjectionCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $runtimes = [];
        $owners = [];

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
            $references = [];
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
                null,
                $references,
            ]);

            $runtimeId = 'ultra.projection.runtime.' . $serviceId;
            $container->setDefinition($runtimeId, $runtime);

            $runtimes[] = new Reference($runtimeId);
        }

        $container->register(ProjectionCatalog::class, ProjectionCatalog::class)->setArguments($runtimes);
    }
}
