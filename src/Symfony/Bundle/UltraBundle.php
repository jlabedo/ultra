<?php

declare(strict_types=1);

namespace Ultra\SymfonyBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Ultra\Container\ContainerExtension;
use Ultra\Coordination_Postgres\PostgresCoordinationSchema;
use Ultra\EventStore_Postgres\PostgresEventStoreHStore;
use Ultra\MessageHandling_PostgresQueue\PostgresAsyncQueueSchema;
use Ultra\Projection_Postgres\PostgresProjectionImplementation;
use Ultra\SymfonyBundle\Coordination\PostgresCoordinationConfiguration;
use Ultra\SymfonyBundle\EventStore\PostgresEventStoreConfiguration;
use Ultra\SymfonyBundle\MessageHandling\PostgresAsyncQueueConfiguration;
use Ultra\SymfonyBundle\Projection\PostgresProjectionConfiguration;

final class UltraBundle extends AbstractBundle
{
    /**
     * @param list<ContainerExtension> $extensions
     */
    public function __construct(
        private readonly array $extensions = [],
    ) {}

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        foreach ([] === $this->extensions ? self::defaultExtensions() : $this->extensions as $extension) {
            $extension->build($container);
        }
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition
            ->rootNode()
            ->children()
            ->arrayNode('schema')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('migrations_directory')
            ->defaultValue('migrations/schema')
            ->cannotBeEmpty();

        if (class_exists(PostgresEventStoreHStore::class)) {
            PostgresEventStoreConfiguration::configure($definition);
        }
        if (class_exists(PostgresProjectionImplementation::class)) {
            PostgresProjectionConfiguration::configure($definition);
        }
        if (class_exists(PostgresAsyncQueueSchema::class)) {
            PostgresAsyncQueueConfiguration::configure($definition);
        }
        if (class_exists(PostgresCoordinationSchema::class)) {
            PostgresCoordinationConfiguration::configure($definition);
        }
    }

    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $schema = $config['schema'] ?? null;
        $directory = is_array($schema) ? $schema['migrations_directory'] ?? null : null;
        if (!is_string($directory)) {
            throw new \InvalidArgumentException('The schema migrations directory must be a string.');
        }
        if (!Path::isAbsolute($directory) && $container->hasParameter('kernel.project_dir')) {
            $projectDirectory = $container->getParameter('kernel.project_dir');
            assert(is_string($projectDirectory), 'The Symfony project directory is a string.');
            $directory = Path::makeAbsolute($directory, $projectDirectory);
        }

        $configurator->parameters()->set('ultra.schema.migrations_directory', $directory);

        $eventStore = $config['event_store'] ?? null;
        $postgres = is_array($eventStore) ? $eventStore['postgres'] ?? null : null;
        if (is_array($postgres)) {
            PostgresEventStoreConfiguration::load($postgres, $container);
        }

        $projection = $config['projection'] ?? null;
        $postgres = is_array($projection) ? $projection['postgres'] ?? null : null;
        if (is_array($postgres)) {
            PostgresProjectionConfiguration::load($postgres, $container);
        }

        $queue = $config['async_queue'] ?? null;
        $postgres = is_array($queue) ? $queue['postgres'] ?? null : null;
        if (is_array($postgres)) {
            PostgresAsyncQueueConfiguration::load($postgres, $container);
        }

        $coordination = $config['coordination'] ?? null;
        $postgres = is_array($coordination) ? $coordination['postgres'] ?? null : null;
        if (is_array($postgres)) {
            PostgresCoordinationConfiguration::load($postgres, $container);
        }
    }

    /**
     * @return list<ContainerExtension>
     */
    public static function defaultExtensions(): array
    {
        $extensionClasses = [
            'Ultra\\Events\\EventsExtension',
            'Ultra\\MessageHandling\\MessageHandlingExtension',
            'Ultra\\UnitOfWork\\UnitOfWorkExtension',
            'Ultra\\Mapper_Bundle\\MapperExtension',
            'Ultra\\EventSourcedState\\EventSourcedStateExtension',
            'Ultra\\Schema\\Symfony\\SchemaExtension',
            'Ultra\\MappedTable\\Symfony\\MappedTableExtension',
            'Ultra\\StateStore_Bundle\\StateStoreExtension',
            'Ultra\\StatefulMessageHandling\\StatefulMessageHandlingExtension',
            'Ultra\\Projection\\Bundle\\ProjectionExtension',
        ];

        $extensions = [];
        foreach ($extensionClasses as $extensionClass) {
            if (!class_exists($extensionClass)) {
                continue;
            }

            // @mago-expect analysis:ambiguous-instantiation-target
            $extension = new $extensionClass();
            // @mago-expect analysis:impossible-condition
            if (!$extension instanceof ContainerExtension) {
                throw new \LogicException(sprintf(
                    'Ultra extension "%s" must implement "%s".',
                    $extensionClass,
                    ContainerExtension::class,
                ));
            }

            $extensions[] = $extension;
        }

        return $extensions;
    }
}
