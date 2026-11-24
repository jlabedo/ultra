<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\Argument\BoundArgument;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Projection\Bundle\ProjectionExtension;
use Ultra\Projection\Bundle\ProjectionImplementationInterface;
use Ultra\Projection\ProjectionPhase;
use Ultra\Projection\ProjectionState;
use Ultra\Projection_Postgres\PostgresProjectionImplementation;
use Ultra\Schema\Symfony\SchemaExtension;

final class PostgresProjectionSchemaTest extends TestCase
{
    public function test_symfony_generates_and_renames_the_tables_used_by_postgres_projections(): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to verify the generated projection tables.');
        }

        $suffix = bin2hex(random_bytes(8));
        $directory = sys_get_temp_dir() . '/ultra_projection_schema_' . $suffix;
        mkdir($directory);
        $parameters = new DsnParser()->parse($dsn);
        $parameters['driver'] = 'pdo_pgsql';
        $connection = DriverManager::getConnection($parameters);

        try {
            $first = $this->generate($directory, 'Reporting_', 'create-storage');
            $second = $this->generate($directory, 'legacy_', 'rename-storage', sharedPrefix: 'analytics_');

            $connection->beginTransaction();
            $connection->executeStatement('CREATE SCHEMA projection_schema_' . $suffix);
            $connection->executeStatement('SET LOCAL search_path TO projection_schema_' . $suffix);
            $creation = file_get_contents($directory . '/migrations/000001-create-storage/migration.sql');
            self::assertIsString($creation);
            $connection->executeStatement($creation);

            $first->set('test.connection', $connection);
            $before = $first->get(ProjectionImplementationInterface::class);
            self::assertInstanceOf(PostgresProjectionImplementation::class, $before);
            $state = new ProjectionState('report', 1, ProjectionPhase::Building, 'fingerprint');
            $before->createStateRepository()->save($state);
            self::assertTrue($before->createSegmentRepository()->ensureInitialized('report'));
            $before->createDirtyKeyTracker()->bump('projection', 'report');
            self::assertTrue($before->createCoordinatorLease()->acquire('report', 'worker'));

            $renameSql = file_get_contents($directory . '/migrations/000002-rename-storage/migration.sql');
            self::assertIsString($renameSql);
            self::assertStringNotContainsString('DROP TABLE', $renameSql);
            foreach (['states', 'segments', 'dirty_keys', 'coordinator_lease'] as $table) {
                self::assertStringContainsString(
                    sprintf('ALTER TABLE "reporting_%s" RENAME TO "analytics_%s"', $table, $table),
                    $renameSql,
                );
            }
            $connection->executeStatement($renameSql);

            $second->set('test.connection', $connection);
            $after = $second->get(ProjectionImplementationInterface::class);
            self::assertInstanceOf(PostgresProjectionImplementation::class, $after);
            self::assertEquals($state, $after->createStateRepository()->find('report'));
            self::assertFalse($after->createSegmentRepository()->ensureInitialized('report'));
            self::assertCount(1, $after->createDirtyKeyTracker()->pendingForChannel('projection'));
            self::assertTrue($after->createCoordinatorLease()->hasLiveCoordinator('report'));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->close();
            new Filesystem()->remove($directory);
        }
    }

    private function generate(
        string $directory,
        string $servicePrefix,
        string $name,
        ?string $sharedPrefix = null,
    ): Container {
        $builder = new ContainerBuilder();
        new SchemaExtension()->build($builder);
        new ProjectionExtension()->build($builder);
        $builder->addCompilerPass(new AddConsoleCommandPass());
        $builder->setParameter('ultra.schema.migrations_directory', $directory . '/migrations');
        if (null !== $sharedPrefix) {
            $builder->setParameter('ultra.projection.postgres.table_prefix', $sharedPrefix);
        }
        $builder->register(EventStore::class, InMemoryEventStore::class);
        $builder->register('test.connection', Connection::class)->setSynthetic(true)->setPublic(true);
        $builder
            ->register('test.projection_implementation', PostgresProjectionImplementation::class)
            ->setAutowired(true)
            ->setArguments(['$connection' => new Reference('test.connection')])
            ->setBindings(['$tablePrefix' => new BoundArgument($servicePrefix)]);
        $builder->setAlias(ProjectionImplementationInterface::class, 'test.projection_implementation')->setPublic(true);
        $builder->compile();

        $class = 'ProjectionSchemaContainer' . bin2hex(random_bytes(8));
        $file = $directory . '/' . $class . '.php';
        file_put_contents($file, new PhpDumper($builder)->dump(['class' => $class]));
        require $file;
        $container = new $class();
        self::assertSame(
            $sharedPrefix ?? $servicePrefix,
            $container->getParameter('ultra.projection.postgres.table_prefix'),
        );
        $loader = $container->get('console.command_loader');
        self::assertInstanceOf(CommandLoaderInterface::class, $loader);

        $console = new Application();
        $console->setAutoExit(false);
        $console->setCommandLoader($loader);
        $tester = new ApplicationTester($console);
        $arguments = ['command' => 'ultra:schema:generate', 'name' => $name];
        // The synthetic connection is unset until after generation.
        self::assertSame(0, $tester->run($arguments), $tester->getDisplay());

        return $container;
    }
}
