<?php

declare(strict_types=1);

namespace Ultra\Mapper_Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Mapper\RuntimeNormalizerInterface;
use Ultra\Mapper_Bundle\Cache\MapperCacheWarmer;
use Ultra\Mapper_Bundle\TagNames;

final class MapperCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $classes = $this->collectTaggedClasses($container);

        $runtimeTypes = $this->collectRuntimeNormalizedTypes($container);

        $container->register(MapperCacheWarmer::class, MapperCacheWarmer::class)
            ->setArguments([$classes, $runtimeTypes])
            ->addTag('kernel.cache_warmer')
        ;
    }

    /**
     * @return list<class-string>
     */
    private function collectTaggedClasses(ContainerBuilder $container): array
    {
        $classes = $container->hasParameter('ultra_mapper.normalizable_classes')
            ? $container->getParameter('ultra_mapper.normalizable_classes')
            : [];

        if (!is_array($classes)) {
            $classes = [];
        }

        foreach ($container->findTaggedResourceIds(TagNames::NORMALIZABLE) as $id => $tags) {
            foreach ($tags as $tag) {
                $class = $tag['class'] ?? $container->findDefinition($id)->getClass();
                if (null === $class) {
                    throw new \LogicException(sprintf(
                        'Service "%s" tagged "%s" has no class; use tag attribute "class" to specify one.',
                        $id,
                        TagNames::NORMALIZABLE,
                    ));
                }
                $classes[] = $class;
            }
        }

        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes;
    }

    /**
     * @return array<class-string, true>
     */
    private function collectRuntimeNormalizedTypes(ContainerBuilder $container): array
    {
        $types = [];

        foreach ($container->findTaggedServiceIds(TagNames::RUNTIME_NORMALIZER) as $id => $tags) {
            $definition = $container->findDefinition($id);
            $class = $definition->getClass();
            if (null === $class) {
                throw new \LogicException(sprintf(
                    'Service "%s" tagged "%s" has no class.',
                    $id,
                    TagNames::RUNTIME_NORMALIZER,
                ));
            }
            $class = $container->getParameterBag()->resolveValue($class);
            if (!is_a($class, RuntimeNormalizerInterface::class, true)) {
                throw new \LogicException(sprintf(
                    'Service "%s" tagged "%s" must implement %s.',
                    $id,
                    TagNames::RUNTIME_NORMALIZER,
                    RuntimeNormalizerInterface::class,
                ));
            }
            foreach ($class::handledTypes() as $type) {
                $types[$type] = true;
            }
        }

        ksort($types);

        return $types;
    }
}
