<?php

declare(strict_types=1);

namespace Ultra\SymfonyBundle\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
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
use Ultra\MessageHandling\Async\AsyncMessageId;
use Ultra\MessageHandling\Async\AsyncRetryPolicy;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\Tests\Support\QueueMessage;
use Ultra\MessageHandling_PostgresQueue\MapperAsyncEnvelopeCodec;
use Ultra\MessageHandling_PostgresQueue\PostgresAsyncQueue;
use Ultra\MessageHandling_PostgresQueue\PostgresAsyncQueueSchema;
use Ultra\Schema\IndexDefinition;
use Ultra\Schema\Migration\MigrationGenerator;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\Symfony\SchemaExtension;
use Ultra\Schema\TableDefinition;
use Ultra\SymfonyBundle\UltraBundle;
use Ultra\Testing\PostgresConnectionSupport;

final class PostgresInfrastructureMigrationTest extends TestCase
{
    public function test_configured_queue_and_coordination_tables_support_the_postgres_adapters(): void
    {
        $this->withMigrationWorkspace(function (Connection $connection, string $directory): void {
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
            self::assertStringContainsString('WHERE lease_id IS NULL AND failed_at IS NULL;', $creation);
            self::assertStringContainsString('WHERE lease_id IS NOT NULL AND failed_at IS NULL;', $creation);
            self::assertStringContainsString('WHERE schedule_key IS NOT NULL AND failed_at IS NULL;', $creation);
            self::assertStringContainsString('WHERE failed_at IS NOT NULL;', $creation);
            self::assertStringContainsString('CHECK (revision > 0)', $creation);

            $this->applyMigration($connection, $creation);

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
            $this->applyMigration($connection, $update);

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
        });
    }

    public function test_adding_dead_letters_preserves_existing_pending_messages_and_their_attempts(): void
    {
        $this->withMigrationWorkspace(function (Connection $connection, string $directory): void {
            $current = new PostgresAsyncQueueSchema()->definition()->tables[0];
            $legacy = new TableDefinition(
                $current->id,
                $current->name,
                columns: array_values(array_diff_key(
                    $current->columnsById(),
                    array_flip([
                        'failed_at',
                        'failure_class',
                        'failure_message',
                    ]),
                )),
                indexes: [
                    new IndexDefinition(
                        'schedule_key',
                        'async_queue_messages_schedule_key_idx',
                        columns: ['queue_name', 'schedule_key'],
                        predicate: 'schedule_key IS NOT NULL',
                    ),
                    new IndexDefinition(
                        'available_unleased',
                        'async_queue_messages_available_unleased_idx',
                        columns: ['queue_name', 'available_at', 'id'],
                        predicate: 'lease_id IS NULL',
                    ),
                    new IndexDefinition(
                        'available_leased',
                        'async_queue_messages_available_leased_idx',
                        columns: ['queue_name', 'leased_until', 'available_at', 'id'],
                        predicate: 'lease_id IS NOT NULL',
                    ),
                ],
                primaryKey: $current->primaryKey,
            );

            $history = $directory . '/migrations/schema';
            $legacyId = new MigrationGenerator($history)->generate(new SchemaDefinition([$legacy]), 'create-queue');
            self::assertNotNull($legacyId);
            $creation = file_get_contents($history . '/' . $legacyId . '/migration.sql');
            self::assertIsString($creation);
            $this->applyMigration($connection, $creation);

            $codec = new MapperAsyncEnvelopeCodec(new UltraNormalizer());
            $message = new QueueMessage('pending');
            $id = new AsyncMessageId('existing-message');
            $encoded = $codec->encode(new AsyncEnvelope($message, MessageContext::empty()));
            $connection->executeStatement(
                "INSERT INTO async_queue_messages (id, queue_name, body, headers, available_at, attempts)
                    VALUES (?, 'default', ?, CAST(? AS JSONB), statement_timestamp(), 1)",
                [$id->value, $encoded->body, json_encode($encoded->headers->all(), JSON_THROW_ON_ERROR)],
                [ParameterType::STRING, ParameterType::BINARY, ParameterType::STRING],
            );

            $this->generate($directory, ['async_queue' => ['postgres' => []]], 'add-dead-letters');
            $update = file_get_contents($history . '/000002-add-dead-letters/migration.sql');
            self::assertIsString($update);
            $this->applyMigration($connection, $update);

            $queue = new PostgresAsyncQueue($connection, $codec, retryPolicy: new AsyncRetryPolicy(maxAttempts: 2));
            $delivery = $queue->reserve();
            self::assertNotNull($delivery);
            self::assertEquals($id, $delivery->messageId);
            self::assertEquals($message, $delivery->envelope->message);
            self::assertSame(2, $delivery->attempts);
            $queue->release($delivery, reason: new \RuntimeException('Still failing'));
            self::assertSame(1, $queue->countFailed());
            self::assertNull($queue->reserve());
        });
    }

    /** @param callable(Connection, string): void $test */
    private function withMigrationWorkspace(callable $test): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to verify the generated infrastructure tables.');
        }

        $schema = 'infrastructure_migration_' . bin2hex(random_bytes(8));
        $directory = sys_get_temp_dir() . '/' . $schema;
        mkdir($directory);
        $connection = PostgresConnectionSupport::connection($dsn);

        try {
            $connection->executeStatement('CREATE SCHEMA ' . $schema);
            $connection->executeStatement('SET search_path TO ' . $schema);
            $test($connection, $directory);
        } finally {
            if ($connection->isConnected()) {
                $connection->executeStatement('DROP SCHEMA IF EXISTS ' . $schema . ' CASCADE');
            }
            $connection->close();
            new Filesystem()->remove($directory);
        }
    }

    private function applyMigration(Connection $connection, string $sql): void
    {
        $native = $connection->getNativeConnection();
        if ($native instanceof \PgSql\Connection) {
            self::assertNotFalse(@pg_query($native, $sql), pg_last_error($native));

            return;
        }

        $connection->executeStatement($sql);
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
