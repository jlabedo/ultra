<?php

declare(strict_types=1);

namespace Ultra\StateStore_Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\Symfony\MappedRowSetCompilerPass;
use Ultra\Mapper\RuntimeNormalizerInterface;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Bundle\TagNames as MapperTagNames;
use Ultra\Mapper_Compiler\NormalizationShapeAnalyzer;
use Ultra\StateStore\Attribute\StateCollection;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\StateMetadataFactory;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateRowSets;
use Ultra\StateStore_Bundle\TagNames;
use Ultra\UnitOfWork\TagNames as UnitOfWorkTagNames;

/** @phpstan-import-type Shape from NormalizationShapeAnalyzer */
final class StateStoreCompilerPass implements CompilerPassInterface
{
    public function __construct(
        private readonly ?StateMetadataFactory $metadataFactory = null,
    ) {}

    public function process(ContainerBuilder $container): void
    {
        $stateClasses = $this->collectStateClasses($container);
        $normalizers = $this->collectRuntimeNormalizers($container);
        $shapeAnalyzer = new NormalizationShapeAnalyzer($normalizers);
        $identityShapes = $this->identityShapes($stateClasses, $shapeAnalyzer);
        $metadataFactory = $this->metadataFactory ?? new StateMetadataFactory($identityShapes);
        $allMetadata = [];
        if ([] !== $stateClasses && !$container->has(UltraNormalizer::class)) {
            throw new \LogicException(sprintf(
                'State store states were discovered, but no "%s" service is registered.',
                UltraNormalizer::class,
            ));
        }

        foreach ($stateClasses as $stateClass) {
            if (!class_exists($stateClass)) {
                continue;
            }

            $reflection = $container->getReflectionClass($stateClass);
            assert(null !== $reflection, 'Discovered state classes must be reflectable.');
            if ($reflection->isAbstract()) {
                continue;
            }

            $allMetadata[$stateClass] = $metadataFactory->create($stateClass);
        }

        $itemClasses = [];
        foreach ($allMetadata as $metadata) {
            foreach ($metadata->collections as $collection) {
                $itemClasses[$collection->itemClass] = true;
            }
        }

        $rootMetadata = array_diff_key($allMetadata, $itemClasses);
        $container->setDefinition(StateMetadataRegistry::class, new Definition(StateMetadataRegistry::class, [
            array_map(static fn($metadata): array => $metadata->toArray(), $rootMetadata),
        ]));

        $bindings = [];
        if ([] !== $rootMetadata) {
            if (!$container->hasParameter(MappedRowSetCompilerPass::MAPPINGS)) {
                throw new \LogicException('StateStore requires MappedTableExtension to compile state mappings.');
            }
            /** @var array<class-string<MappedRowSetDefinition>, MappedRowSetMapping> $mappings */
            $mappings = $container->getParameter(MappedRowSetCompilerPass::MAPPINGS);
            $bindings = new StateRowSetBindings($mappings, $identityShapes, $normalizers)->compile($rootMetadata);
        }
        if (!$container->hasDefinition(MappedTableUnitOfWork::class)) {
            // A StateStore used solely for manual query expectations still participates in the flush.
            $container
                ->register(MappedTableUnitOfWork::class, MappedTableUnitOfWork::class)
                ->setArguments([[], []])
                ->addTag(UnitOfWorkTagNames::UNIT_OF_WORK);
        }
        $container
            ->register(StateRowSets::class, StateRowSets::class)
            ->setArguments([
                array_map(static fn(string $definition): Reference => new Reference($definition), $bindings),
                new Reference(MappedTableUnitOfWork::class),
            ]);
    }

    /**
     * @param list<string> $classes
     * @return array<class-string, Shape>
     */
    private function identityShapes(array $classes, NormalizationShapeAnalyzer $analyzer): array
    {
        $seen = [];
        $shapes = [];
        while (null !== ($class = array_pop($classes))) {
            if (isset($seen[$class]) || !class_exists($class)) {
                continue;
            }
            $seen[$class] = true;
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }
            foreach ($reflection->getProperties() as $property) {
                if ([] !== $property->getAttributes(StateId::class)) {
                    if (!$property->isPublic() || $property->isVirtual() || $property->isStatic()) {
                        throw new \LogicException(sprintf(
                            'State id %s::$%s must be stored and public.',
                            $class,
                            $property->getName(),
                        ));
                    }
                    $shapes[$class] = $analyzer->forProperty($property);
                }
                foreach ($property->getAttributes(StateCollection::class) as $attribute) {
                    $classes[] = $attribute->newInstance()->itemClass;
                }
            }
        }

        return $shapes;
    }

    /** @return array<class-string, class-string<RuntimeNormalizerInterface>> */
    private function collectRuntimeNormalizers(ContainerBuilder $container): array
    {
        $normalizers = [];
        foreach ($container->findTaggedServiceIds(MapperTagNames::RUNTIME_NORMALIZER) as $id => $_) {
            $class = $container->findDefinition($id)->getClass();
            if (null !== $class) {
                $class = $container->getParameterBag()->resolveValue($class);
            }
            if (!is_string($class) || !is_a($class, RuntimeNormalizerInterface::class, true)) {
                continue;
            }
            // @mago-expect analysis:possibly-static-access-on-interface
            foreach ($class::handledTypes() as $type) {
                $normalizers[$type] = $class;
            }
        }

        return $normalizers;
    }

    /**
     * @return list<string>
     */
    private function collectStateClasses(ContainerBuilder $container): array
    {
        $classes = [];

        foreach ($container->findTaggedResourceIds(TagNames::STATE) as $id => $tags) {
            foreach ($tags as $tag) {
                $class = $tag['class'] ?? $container->findDefinition($id)->getClass();
                if (!is_string($class)) {
                    throw new \LogicException(sprintf(
                        'Service "%s" tagged "%s" has no class; use tag attribute "class" to specify one.',
                        $id,
                        TagNames::STATE,
                    ));
                }

                $classes[] = $class;
            }
        }

        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes;
    }
}
