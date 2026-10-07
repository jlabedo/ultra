<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState_Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\EventSourcedState\EventSourcedStateStore;
use Ultra\EventStore\EventStore;
use Ultra\Mapper\UltraNormalizer;

final class EntityLoaderCompilerPass implements CompilerPassInterface
{
    public function __construct(
        private readonly string $outputDir,
        private readonly string $entityTag = 'ultra_event_sourced_state.entity',
        private readonly ?EventSourcedEntityAnalyzer $analyzer = null,
        private readonly ?EventSourcedEntityLoaderGenerator $generator = null,
    ) {}

    public function process(ContainerBuilder $container): void
    {
        $analyzer = $this->analyzer ?? new EventSourcedEntityAnalyzer();
        $generator = $this->generator ?? new EventSourcedEntityLoaderGenerator();

        /** @var array<class-string, Reference> $loaderReferences */
        $loaderReferences = [];

        foreach (array_keys($container->findTaggedResourceIds($this->entityTag)) as $serviceId) {
            $definition = $container->getDefinition($serviceId);
            $entityClass = $definition->getClass() ?? $serviceId;

            if (!class_exists($entityClass)) {
                continue;
            }

            $entityRef = new \ReflectionClass($entityClass);
            if ($entityRef->isAbstract()) {
                continue;
            }

            $entityDefinition = $analyzer->analyze($entityClass);
            $loaderClass = $this->loaderClassName($entityClass);
            $filePath = rtrim($this->outputDir, '/') . '/' . $loaderClass . '.php';
            $generator->generate($loaderClass, $entityDefinition, $filePath);

            $loaderServiceId = 'ultra.event_sourced_state.loader.' . substr(md5($entityClass), 0, 12);
            $loaderDefinition = new Definition($loaderClass, [
                new Reference(UltraNormalizer::class),
            ]);
            $loaderDefinition->setFile($filePath)->setPublic(false);
            $container->setDefinition($loaderServiceId, $loaderDefinition);
            $loaderReferences[$entityClass] = new Reference($loaderServiceId);
        }

        $storeDefinition = new Definition(EventSourcedStateStore::class, [
            $loaderReferences,
            new Reference(EventStore::class),
        ]);
        $storeDefinition->setPublic(true);
        $container->setDefinition(EventSourcedStateStore::class, $storeDefinition);
    }

    /**
     * @param class-string $entityClass
     */
    private function loaderClassName(string $entityClass): string
    {
        $short = substr($entityClass, (int) strrpos($entityClass, '\\') + 1);
        $hash = md5($entityClass . '@' . $this->outputDir);

        return 'CompiledEventSourcedEntityLoader_' . $short . '_' . substr($hash, 0, 8);
    }
}
