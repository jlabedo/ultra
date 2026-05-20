<?php

declare(strict_types=1);

namespace Ultra\Mapper_Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Mapper\RuntimeNormalizerInterface;
use Ultra\Mapper_Bundle\TagNames;
use Ultra\Mapper_Compiler\NormalizerGenerator;

final readonly class MapperCompilerPass implements CompilerPassInterface
{
    public function __construct(private string $outputDir) {}

    public function process(ContainerBuilder $container): void
    {
        $classes = $this->collectTaggedClasses($container);

        $runtimeTypes = $this->collectRuntimeNormalizedTypes($container);

        $this->generateNormalizers($classes, $runtimeTypes);
    }

    /**
     * @return list<class-string>
     */
    private function collectTaggedClasses(ContainerBuilder $container): array
    {
        $classes = [];

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

    /**
     * @param list<class-string>        $classes
     * @param array<class-string, true> $runtimeNormalizedTypes
     */
    private function generateNormalizers(array $classes, array $runtimeNormalizedTypes): void
    {
        if ([] === $classes) {
            return;
        }

        if (!class_exists(NormalizerGenerator::class)) {
            throw new \RuntimeException('Cannot compile mapper normalizers because ultra/mapper-compiler is not installed.');
        }

        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0o777, true);
        }

        foreach ($classes as $className) {
            if (!class_exists($className)) {
                throw new \RuntimeException(sprintf('Cannot compile mapper normalizer for missing class "%s".', $className));
            }

            $normalizerClassBaseName = '__Normalizer_'.str_replace('\\', '_', ltrim($className, '\\'));
            $normalizerClass = 'Ultra\Normalizer\Generated\\'.$normalizerClassBaseName;
            $cacheFile = rtrim($this->outputDir, '/').'/'.$normalizerClassBaseName.'.php';
            $fileGen = NormalizerGenerator::for($className, $normalizerClass, $runtimeNormalizedTypes);

            file_put_contents($cacheFile, $fileGen->generate());
        }
    }
}
