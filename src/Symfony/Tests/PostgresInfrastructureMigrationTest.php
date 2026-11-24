<?php

declare(strict_types=1);

namespace Ultra\SymfonyBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\Filesystem\Filesystem;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLease;
use Ultra\Coordination_Postgres\Refresh\PostgresRefreshPublication;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTracker;
use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\Async\AsyncEnvelope;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\Tests\Support\QueueMessage;
use Ultra\MessageHandling_PostgresQueue\MapperAsyncEnvelopeCodec;
use Ultra\MessageHandling_PostgresQueue\PostgresAsyncQueue;
use Ultra\Schema\Symfony\SchemaExtension;
use Ultra\SymfonyBundle\UltraBundle;
use Ultra\Testing\PostgresConnectionSupport;

final class PostgresInfrastructureMigrationTest extends TestCase
{
    public function test_configured_queue_and_coordination_tables_support_the_postgres_adapters(): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to verify the generated infrastructure tables.');
        }

        $suffix = bin2hex(random_bytes(8));
        $schema = 'infrastructure_migration_' . $suffix;
        $directory = sys_get_temp_dir() . '/' . $schema;
        mkdir($directory);
        $connection = PostgresConnectionSupport::connection($dsn);

        try {
            $first = [
                'async_queue' => ['postgres' => []],
                'coordination' => ['postgres' => [
                    'dirty_keys' => 'refresh_dirty_keys',
                    'refresh_revisions' => 'refresh_revisions',
                ]],
            ];
            $this->generate($directory, $first, 'create-infrastructure');
            $creation = file_get_contents($directory . '/migrations/schema/000001-create-infrastructure/migration.sql');
            self::assertIsString($creation);
            self::assertStringNotContainsString('CREATE TABLE "coordination_leases"', $creation);
            self::assertStringContainsString('WHERE lease_id IS NULL;', $creation);
            self::assertStringContainsString('WHERE lease_id IS NOT NULL;', $creation);
            self::assertStringContainsString('WHERE schedule_key IS NOT NULL;', $creation);
            self::assertStringContainsString('CHECK (revision > 0)', $creation);

            $connection->executeStatement('CREATE SCHEMA ' . $schema);
            $connection->executeStatement('SET search_path TO ' . $schema);
            $connection->executeStatement($creation);

            $codec = new MapperAsyncEnvelopeCodec(new UltraNormalizer());
            $queue = new PostgresAsyncQueue($connection, $codec);
            $queue->enqueue(new AsyncEnvelope(new QueueMessage('pending'), MessageContext::empty()));
            new PostgresDirtyKeyTracker($connection, 'refresh_dirty_keys')->bump('refresh', 'account');
            self::assertTrue(new PostgresRefreshPublication($connection, 'refresh_revisions')->publish(
                'refresh',
                0,
                static function (): void {},
            ));

            $second = [
                'async_queue' => ['postgres' => ['table_prefix' => 'worker_']],
                'coordination' => ['postgres' => [
                    'dirty_keys' => 'worker_dirty_keys',
                    'leases' => 'worker_leases',
                    'refresh_revisions' => 'worker_revisions',
                ]],
            ];
            $this->generate($directory, $second, 'update-infrastructure');
            $update = file_get_contents($directory . '/migrations/schema/000002-update-infrastructure/migration.sql');
            self::assertIsString($update);
            self::assertStringNotContainsString('DROP ', $update);
            self::assertStringContainsString(
                'ALTER INDEX "async_queue_messages_available_unleased_idx" RENAME TO "worker_messages_available_unleased_idx"',
                $update,
            );
            $connection->executeStatement($update);

            $queue = new PostgresAsyncQueue($connection, $codec, tablePrefix: 'worker_');
            $delivery = $queue->reserve();
            self::assertNotNull($delivery);
            self::assertEquals(new QueueMessage('pending'), $delivery->envelope->message);
            $queue->acknowledge($delivery);
            self::assertNull($queue->reserve());
            self::assertCount(
                1,
                new PostgresDirtyKeyTracker($connection, 'worker_dirty_keys')->pendingForChannel('refresh'),
            );
            self::assertSame(1, new PostgresRefreshPublication($connection, 'worker_revisions')->revision('refresh'));
            self::assertTrue(new PostgresCoordinatorLease($connection, 'worker_leases')->acquire('refresh', 'worker'));
            self::assertStringContainsString('No schema changes.', $this->generate($directory, $second, 'unchanged'));
        } finally {
            if ($connection->isConnected()) {
                $connection->executeStatement('DROP SCHEMA IF EXISTS ' . $schema . ' CASCADE');
            }
            $connection->close();
            new Filesystem()->remove($directory);
        }
    }

    /** @param array<string, mixed> $config */
    private function generate(string $directory, array $config, string $name): string
    {
        $builder = new ContainerBuilder();
        $bundle = new UltraBundle([new SchemaExtension()]);
        $bundle->build($builder);
        $builder->registerExtension($bundle->getContainerExtension());
        $builder->addCompilerPass(new AddConsoleCommandPass());
        $builder->setParameter('kernel.project_dir', $directory);
        $builder->loadFromExtension('ultra', $config);
        $builder->compile();

        $class = 'InfrastructureMigrationContainer' . bin2hex(random_bytes(8));
        $file = $directory . '/' . $class . '.php';
        file_put_contents($file, new PhpDumper($builder)->dump(['class' => $class]));
        require $file;
        $loader = new $class()->get('console.command_loader');
        self::assertInstanceOf(CommandLoaderInterface::class, $loader);
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCommandLoader($loader);
        $tester = new ApplicationTester($application);
        self::assertSame(
            0,
            $tester->run(['command' => 'ultra:schema:generate', 'name' => $name]),
            $tester->getDisplay(),
        );

        return $tester->getDisplay();
    }
}
