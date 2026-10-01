<?php

declare(strict_types=1);

namespace Ultra\StateStore_Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetAdapter;
use Ultra\MappedTable\MappedRowSetAdapterFactory;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper\RuntimeNormalizerInterface;
use Ultra\Mapper_Bundle\TagNames as MapperTagNames;
use Ultra\Mapper_Compiler\NormalizationShapeAnalyzer;
use Ultra\StateStore\GuardLockAdapter;
use Ultra\StateStore\StateMetadataFactory;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateTableRegistry;
use Ultra\StateStore_Bundle\TagNames;

final class StateStoreCompilerPass implements CompilerPassInterface
{
    private const string UNIT_OF_WORK_TAG = 'ultra_unit_of_work.unit_of_work';

    public function __construct(
        private readonly ?StateMetadataFactory $metadataFactory = null,
    ) {}

    public function process(ContainerBuilder $container): void
    {
        $stateClasses = $this->collectStateClasses($container);
        $shapeAnalyzer = new NormalizationShapeAnalyzer($this->collectRuntimeNormalizers($container));
        $identityShapes = [];
        foreach ($stateClasses as $stateClass) {
            if (!class_exists($stateClass)) {
                continue;
            }
            foreach ((new \ReflectionClass($stateClass))->getProperties() as $property) {
                if ([] !== $property->getAttributes(\Ultra\StateStore\Attribute\StateId::class)) {
                    $identityShapes[$stateClass] = $shapeAnalyzer->forProperty($property);
                    break;
                }
            }
        }
        $metadataFactory = $this->metadataFactory ?? new StateMetadataFactory($identityShapes);
        $allMetadata = [];
        $stateMetadata = [];
        $tableReferences = [];

        if ([] !== $stateClasses && !$container->has(MappedRowSetAdapterFactory::class)) {
            throw new \LogicException(sprintf(
                'State store states were discovered, but no "%s" service is registered.',
                MappedRowSetAdapterFactory::class,
            ));
        }

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

        $tableKeys = [];
        foreach ($allMetadata as $stateClass => $metadata) {
            if (isset($itemClasses[$stateClass])) {
                continue;
            }
            $stateMetadata[$stateClass] = $metadata->toArray();
            $this->assertTableKey($tableKeys, $metadata->tableName, $metadata->keyColumns());
            foreach ($metadata->collections as $collection) {
                $this->assertTableKey($tableKeys, $collection->tableName, $collection->keyColumns());
            }

            if (!array_key_exists($metadata->tableKey, $tableReferences)) {
                $serviceId = 'ultra.state_store.table.'.substr(md5($metadata->tableKey), 0, 12);
                $adapterServiceId = $serviceId.'.adapter';
                $container->setDefinition(
                    $adapterServiceId,
                    (new Definition(MappedRowSetAdapter::class))
                        ->setFactory([new Reference(MappedRowSetAdapterFactory::class), 'create'])
                        ->setArguments([$metadata->tableName, $metadata->keyColumns()])
                );

                $container->setDefinition(
                    $serviceId,
                    new Definition(MappedRowSet::class, [
                        new Reference($adapterServiceId),
                    ])
                );

                $tableReferences[$metadata->tableKey] = new Reference($serviceId);
            }
        }

        ksort($stateMetadata);
        ksort($tableReferences);

        $container->setDefinition(StateMetadataRegistry::class, new Definition(StateMetadataRegistry::class, [
            $stateMetadata,
        ])->setPublic(false));

        $stateTableRegistryArguments = [$tableReferences];
        $stateTableRegistryArguments[] = $container->has(MappedRowSetAdapterFactory::class)
            ? new Reference(MappedRowSetAdapterFactory::class)
            : null;
        if ($container->has(GuardLockAdapter::class)) {
            $stateTableRegistryArguments[] = [];
            $stateTableRegistryArguments[] = new Reference(GuardLockAdapter::class);
        }

        $container->setDefinition(StateTableRegistry::class, new Definition(StateTableRegistry::class, $stateTableRegistryArguments)
            ->addTag(self::UNIT_OF_WORK_TAG)
        );
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
            if (null === $class || !is_a($class, RuntimeNormalizerInterface::class, true)) {
                continue;
            }
            foreach ($class::handledTypes() as $type) {
                $normalizers[$type] = $class;
            }
        }

        return $normalizers;
    }

    /** @param array<string, list<string>> $known @param list<string> $columns */
    private function assertTableKey(array &$known, string $table, array $columns): void
    {
        sort($columns, SORT_STRING);
        if (isset($known[$table]) && $known[$table] !== $columns) {
            throw new \LogicException(sprintf('State table "%s" is mapped with conflicting key columns.', $table));
        }
        $known[$table] = $columns;
    }

    /**
     * @return list<class-string>
     */
    private function collectStateClasses(ContainerBuilder $container): array
    {
        $classes = [];

        foreach ($container->findTaggedResourceIds(TagNames::STATE) as $id => $tags) {
            foreach ($tags as $tag) {
                $class = $tag['class'] ?? $container->findDefinition($id)->getClass();
                if (null === $class) {
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
