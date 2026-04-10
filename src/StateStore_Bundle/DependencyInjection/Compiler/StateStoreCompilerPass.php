<?php

declare(strict_types=1);

namespace Ultra\StateStore_Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MappedTable\MappedTable;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\Mapper\UltraNormalizer;
use Ultra\StateStore\StateMetadataFactory;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateStore;
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
        $metadataFactory = $this->metadataFactory ?? new StateMetadataFactory();
        $stateMetadata = [];
        $tableReferences = [];

        if ([] !== $stateClasses && ! $container->has(MappedTableAdapter::class)) {
            throw new \LogicException(sprintf(
                'State store states were discovered, but no "%s" service is registered.',
                MappedTableAdapter::class,
            ));
        }

        if ([] !== $stateClasses && ! $container->has(UltraNormalizer::class)) {
            throw new \LogicException(sprintf(
                'State store states were discovered, but no "%s" service is registered.',
                UltraNormalizer::class,
            ));
        }

        foreach ($stateClasses as $stateClass) {
            if (! class_exists($stateClass)) {
                continue;
            }

            $reflection = new \ReflectionClass($stateClass);
            if ($reflection->isAbstract()) {
                continue;
            }

            $metadata = $metadataFactory->create($stateClass);
            $stateMetadata[$stateClass] = $metadata->toArray();

            if (! array_key_exists($metadata->tableKey, $tableReferences)) {
                $serviceId = 'ultra.state_store.table.' . substr(md5($metadata->tableKey), 0, 12);
                $container->setDefinition(
                    $serviceId,
                    (new Definition(MappedTable::class, [
                        new Reference(MappedTableAdapter::class),
                        $metadata->tableName,
                        $metadata->idColumn,
                    ]))
                        ->addTag(self::UNIT_OF_WORK_TAG)
                );

                $tableReferences[$metadata->tableKey] = new Reference($serviceId);
            }
        }

        ksort($stateMetadata);
        ksort($tableReferences);

        $container->setDefinition(StateMetadataRegistry::class, (new Definition(StateMetadataRegistry::class, [
            $stateMetadata,
        ]))->setPublic(false));

        $container->setDefinition(StateTableRegistry::class, (new Definition(StateTableRegistry::class, [
            $tableReferences,
        ]))->setPublic(false));

        $container->setDefinition(StateStore::class, (new Definition(StateStore::class, [
            new Reference(StateMetadataRegistry::class),
            new Reference(StateTableRegistry::class),
            new Reference(UltraNormalizer::class),
        ]))->setPublic(true));
    }

    /**
     * @return list<class-string>
     */
    private function collectStateClasses(ContainerBuilder $container): array
    {
        $classes = $container->hasParameter('ultra_state_store.state_classes')
            ? $container->getParameter('ultra_state_store.state_classes')
            : [];

        if (! is_array($classes)) {
            $classes = [];
        }

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
