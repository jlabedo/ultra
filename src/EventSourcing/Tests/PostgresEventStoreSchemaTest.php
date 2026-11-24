<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\Filesystem\Filesystem;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore_Postgres\LockStrategy\BucketLockStrategy;
use Ultra\Schema\Symfony\SchemaExtension;
use Ultra\SymfonyBundle\UltraBundle;

final class PostgresEventStoreSchemaTest extends TestCase
{
    #[DataProvider('implementations')]
    public function test_symfony_migration_installs_the_configured_event_store(
        string $variant,
        ?string $strategy,
        string $function,
    ): void {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to verify Event Store migrations.');
        }

        $suffix = bin2hex(random_bytes(8));
        $directory = sys_get_temp_dir() . '/ultra_event_store_schema_' . $suffix;
        mkdir($directory);
        $schema = 'event_store_schema_' . $suffix;
        $parameters = new DsnParser()->parse($dsn);
        $parameters['driver'] = 'pdo_pgsql';
        $connection = DriverManager::getConnection($parameters);

        try {
            $builder = new ContainerBuilder();
            $bundle = new UltraBundle([new SchemaExtension()]);
            $bundle->build($builder);
            $builder->registerExtension($bundle->getContainerExtension());
            $builder->addCompilerPass(new AddConsoleCommandPass());
            $builder->setParameter('kernel.project_dir', $directory);
            $builder->register('test.connection', Connection::class)->setSynthetic(true)->setPublic(true);
            $builder->register('test.lock_strategy', BucketLockStrategy::class);
            $builder->setAlias('test.event_store', EventStore::class)->setPublic(true);

            $postgres = ['variant' => $variant, 'connection' => 'test.connection'];
            $prefix = 'event_store_';
            if (null !== $strategy) {
                $prefix = 'journal_';
                $postgres += ['table_prefix' => $prefix, 'lock_strategy' => $strategy];
            }
            $builder->loadFromExtension('ultra', ['event_store' => ['postgres' => $postgres]]);
            $builder->compile();

            $class = 'EventStoreMigrationContainer' . $suffix;
            $file = $directory . '/container.php';
            file_put_contents($file, new PhpDumper($builder)->dump(['class' => $class]));
            require $file;
            $container = new $class();
            $loader = $container->get('console.command_loader');
            self::assertInstanceOf(CommandLoaderInterface::class, $loader);
            $application = new Application();
            $application->setAutoExit(false);
            $application->setCommandLoader($loader);

            $tester = new ApplicationTester($application);
            $arguments = ['command' => 'ultra:schema:generate', 'name' => 'create-event-store'];
            // The Event Store's synthetic connection is still unset.
            self::assertSame(0, $tester->run($arguments), $tester->getDisplay());
            self::assertSame(0, $tester->run($arguments), $tester->getDisplay());
            self::assertStringContainsString('No schema changes.', $tester->getDisplay());

            $sql = file_get_contents($directory . '/migrations/schema/000001-create-event-store/migration.sql');
            self::assertIsString($sql);
            self::assertStringContainsString(sprintf('CREATE FUNCTION "%s%s"', $prefix, $function), $sql);
            if ('hstore' === $variant) {
                self::assertStringContainsString('CREATE EXTENSION IF NOT EXISTS "hstore"', $sql);
                self::assertStringContainsString('CREATE EXTENSION IF NOT EXISTS "btree_gin"', $sql);
                self::assertStringContainsString('USING "gin"', $sql);
            } else {
                self::assertStringContainsString(sprintf('CREATE TABLE "%sevent_domain_ids"', $prefix), $sql);
                self::assertStringNotContainsString('CREATE EXTENSION', $sql);
            }

            $connection->beginTransaction();
            $connection->executeStatement('CREATE SCHEMA ' . $schema);
            $connection->executeStatement('SET LOCAL search_path TO ' . $schema . ', public');
            $connection->executeStatement($sql);
            $container->set('test.connection', $connection);
            $store = $container->get('test.event_store');
            self::assertInstanceOf(EventStore::class, $store);

            $query = StreamQuery::forStream(new DomainIdentifier('order', '42'))->ofTypes('Placed');
            $condition = $store->load($query)->appendCondition();
            $store->append([new Event('Placed', ['total' => 17], ['order' => '42'])], $condition);
            self::assertSame(['total' => 17], $store->load($query)->events[0]->payload);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->close();
            new Filesystem()->remove($directory);
        }
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function implementations(): iterable
    {
        yield 'hstore/default strategy' => ['hstore', null, 'acquire_advisory_locks'];
        yield 'hstore/bucket' => ['hstore', 'bucket', 'acquire_multi_xact'];
        yield 'query-streams/default strategy' => ['query-streams', null, 'acquire_advisory_locks'];
        yield 'query-streams/strategy service' => ['query-streams', 'test.lock_strategy', 'acquire_multi_xact'];
    }
}
