<?php

declare(strict_types=1);

namespace Ultra\Mapper_Bundle;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Mapper\GeneratedNormalizerName;
use Ultra\Mapper\RuntimeNormalizerInterface;
use Ultra\Mapper_Compiler\NormalizerGenerator;

final readonly class MapperCompilerPass implements CompilerPassInterface
{
    public function __construct(
        private string $outputDir,
        private string $generationNamespace = GeneratedNormalizerName::DEFAULT_NAMESPACE,
    ) {}

    public function process(ContainerBuilder $container): void
    {
        $classes = $this->collectTaggedClasses($container);

        $runtimeTypes = $this->collectRuntimeNormalizedTypes($container);

        $this->generateNormalizers($classes, $runtimeTypes);
    }

    /**
     * @return list<string>
     */
    private function collectTaggedClasses(ContainerBuilder $container): array
    {
        $classes = [];

        /** @var array<string, list<array<string, mixed>>> $resources */
        $resources = $container->findTaggedResourceIds(TagNames::NORMALIZABLE);
        foreach ($resources as $id => $tags) {
            foreach ($tags as $tag) {
                $class = $tag['class'] ?? $container->findDefinition($id)->getClass();
                if (!is_string($class)) {
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
            if (!is_string($class) || !class_exists($class) || !is_a($class, RuntimeNormalizerInterface::class, true)) {
                throw new \LogicException(sprintf(
                    'Service "%s" tagged "%s" must implement %s.',
                    $id,
                    TagNames::RUNTIME_NORMALIZER,
                    RuntimeNormalizerInterface::class,
                ));
            }
            // @mago-expect analyser:possibly-static-access-on-interface
            foreach ($class::handledTypes() as $type) {
                $types[$type] = true;
            }
        }

        ksort($types);

        return $types;
    }

    /**
     * @param list<string>        $classes
     * @param array<class-string, true> $runtimeNormalizedTypes
     */
    private function generateNormalizers(array $classes, array $runtimeNormalizedTypes): void
    {
        if ([] === $classes) {
            return;
        }

        if (!class_exists(NormalizerGenerator::class)) {
            throw new \RuntimeException(
                'Cannot compile mapper normalizers because ultra/mapper-compiler is not installed.',
            );
        }

        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0o777, true);
        }

        foreach ($classes as $className) {
            if (!class_exists($className)) {
                throw new \RuntimeException(sprintf(
                    'Cannot compile mapper normalizer for missing class "%s".',
                    $className,
                ));
            }

            $normalizerClass = GeneratedNormalizerName::forType($className, $this->generationNamespace);
            $cacheFile = rtrim($this->outputDir, '/') . '/' . GeneratedNormalizerName::cacheFileName($normalizerClass);
            $fileGen = NormalizerGenerator::for($className, $normalizerClass, $runtimeNormalizedTypes);

            file_put_contents($cacheFile, $fileGen->generate());
        }
    }
}
