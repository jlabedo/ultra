<?php

declare(strict_types=1);

namespace Ultra\Mapper_Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper\RuntimeNormalizerInterface;
use Ultra\Mapper_Bundle\Cache\MapperCacheWarmer;
use Ultra\Mapper_Bundle\TagNames;

final class MapperCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $classes = $this->collectTaggedClasses($container);
        if ($classes === []) {
            return;
        }

        $buildDir = (string) $container->getParameter('kernel.build_dir');
        $cacheDir = $buildDir . '/mapper';

        if (!$container->hasDefinition(UltraNormalizer::class)) {
            $container->register(UltraNormalizer::class, UltraNormalizer::class)
                ->setArguments([$cacheDir]);
        }

        $runtimeTypes = $this->collectRuntimeNormalizedTypes($container);

        $container->register(MapperCacheWarmer::class, MapperCacheWarmer::class)
            ->setArguments([$classes, $cacheDir, $runtimeTypes])
            ->addTag('kernel.cache_warmer');
    }

    /**
     * @return list<class-string>
     */
    private function collectTaggedClasses(ContainerBuilder $container): array
    {
        $classes = [];

        foreach ($container->findTaggedServiceIds(TagNames::NORMALIZABLE) as $id => $tags) {
            $definition = $container->findDefinition($id);
            foreach ($tags as $tag) {
                $class = $tag['class'] ?? $definition->getClass();
                if ($class === null) {
                    throw new \LogicException(sprintf(
                        'Service "%s" tagged "%s" has no class; use tag attribute "class" to specify one.',
                        $id,
                        TagNames::NORMALIZABLE,
                    ));
                }
                $classes[] = $container->getParameterBag()->resolveValue($class);
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
            if ($class === null) {
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
