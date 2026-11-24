<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\Migration\MigrationGenerator;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\Symfony\SchemaExtension;
use Ultra\Schema\TableDefinition;
use Ultra\SymfonyBundle\UltraBundle;
use Ultra\Testing\PostgresConnectionSupport;

final class PostgresMigrationCommandTest extends TestCase
{
    private ?Connection $connection = null;
    private string $schema;
    private string $directory;
    private ApplicationTester $console;

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to verify migration execution.');
        }
        $this->schema = 'migration_execution_' . bin2hex(random_bytes(8));
        $this->directory = sys_get_temp_dir() . '/' . $this->schema;
        $this->connection = PostgresConnectionSupport::connection($dsn);
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema);

        $container = new ContainerBuilder();
        $bundle = new UltraBundle([new SchemaExtension()]);
        $bundle->build($container);
        $container->registerExtension($bundle->getContainerExtension());
        $container->addCompilerPass(new AddConsoleCommandPass());
        $container->register('test.connection', Connection::class)->setSynthetic(true)->setPublic(true);
        $container->setAlias('database_connection', 'test.connection');
        $container->setParameter('kernel.project_dir', $this->directory);
        $container->loadFromExtension('ultra', ['schema' => [
            'metadata_table' => $this->schema . '.migration_journal',
        ]]);
        $container->compile();
        $loader = $container->get('console.command_loader');
        self::assertInstanceOf(CommandLoaderInterface::class, $loader);
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCommandLoader($loader);
        $this->console = new ApplicationTester($application);
        self::assertSame(0, $this->console->run(['command' => 'ultra:schema:migrate', '--help' => true]));
        $container->set('test.connection', $this->connection);
    }

    protected function tearDown(): void
    {
        if (null !== $this->connection) {
            try {
                $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            } finally {
                $this->connection->close();
                new Filesystem()->remove($this->directory);
            }
        }
        parent::tearDown();
    }

    public function test_records_a_baseline_and_adopts_a_doctrine_table_with_only_a_comment(): void
    {
        $connection = $this->connection;
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('CREATE TABLE customers (id text PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE orders (id text PRIMARY KEY)');
        $connection->executeStatement("INSERT INTO orders VALUES ('existing')");
        $connection->executeStatement("COMMENT ON TABLE orders IS 'managed-by:doctrine'");
        $connection->executeStatement('CREATE TABLE doctrine_migration_versions (version text PRIMARY KEY)');
        $connection->executeStatement("INSERT INTO doctrine_migration_versions VALUES ('DoctrineVersion1')");

        $generator = new MigrationGenerator($this->directory . '/migrations/schema');
        $customers = self::table('customers');
        $generator->initialize(new SchemaDefinition([$customers]));
        $id = $generator->generate(new SchemaDefinition([$customers, self::table('orders')]), 'adopt-orders');
        self::assertSame('000001-adopt-orders', $id);
        file_put_contents($this->file($id, 'migration.sql'), "COMMENT ON TABLE orders IS 'managed-by:ultra';\n");

        self::assertStringContainsString('000000-baseline  Baseline required', $this->runCommand('status'));
        self::assertNull($connection->fetchOne("SELECT to_regclass('migration_journal')"));
        self::assertStringContainsString('with ultra:schema:baseline', $this->runCommand('migrate', expectedExit: 1));
        self::assertStringContainsString('Recorded 000000-baseline.', $this->runCommand('baseline'));
        self::assertStringContainsString('already recorded', $this->runCommand('baseline'));
        self::assertSame('managed-by:doctrine', $connection->fetchOne("SELECT obj_description('orders'::regclass)"));
        self::assertStringContainsString('Applied 000001-adopt-orders.', $this->runCommand('migrate'));
        self::assertStringContainsString('No pending migrations.', $this->runCommand('migrate'));
        self::assertStringContainsString('000001-adopt-orders  Applied', $this->runCommand('status'));
        self::assertSame('managed-by:ultra', $connection->fetchOne("SELECT obj_description('orders'::regclass)"));
        self::assertSame('existing', $connection->fetchOne('SELECT id FROM orders'));
        self::assertSame('DoctrineVersion1', $connection->fetchOne('SELECT version FROM doctrine_migration_versions'));
        self::assertNull($connection->fetchOne(
            "SELECT sql_sha256 FROM migration_journal WHERE id = '000000-baseline'",
        ));

        file_put_contents($this->file('000000-baseline', 'snapshot.json'), "\n", FILE_APPEND);
        self::assertStringContainsString('000000-baseline" has been modified', $this->runCommand(
            'status',
            expectedExit: 1,
        ));
    }

    public function test_preserves_committed_entries_and_retries_a_failed_migration(): void
    {
        $connection = $this->connection;
        self::assertInstanceOf(Connection::class, $connection);
        $generator = new MigrationGenerator($this->directory . '/migrations/schema');
        $accounts = self::table('accounts');
        self::assertSame('000001-create-accounts', $generator->generate(
            new SchemaDefinition([$accounts]),
            'create-accounts',
        ));
        $second = $generator->generate(new SchemaDefinition([$accounts, self::table('invoices')]), 'create-invoices');
        self::assertSame('000002-create-invoices', $second);
        $path = $this->file($second, 'migration.sql');
        $sql = file_get_contents($path);
        self::assertIsString($sql);
        $sql .= <<<'SQL'
            CREATE FUNCTION invoice_count() RETURNS bigint LANGUAGE sql AS $ultra_script$
            SELECT count(*) FROM invoices;
            $ultra_script$;
            SQL;
        file_put_contents($path, $sql . "\n");
        file_put_contents($path, 'COMMIT;', FILE_APPEND);

        self::assertStringContainsString('Migration "000002-create-invoices" failed', $this->runCommand(
            'migrate',
            expectedExit: 1,
        ));
        self::assertSame('accounts', $connection->fetchOne("SELECT to_regclass('accounts')::text"));
        self::assertNull($connection->fetchOne("SELECT to_regclass('invoices')"));
        self::assertSame(['000001-create-accounts'], $connection->fetchFirstColumn('SELECT id FROM migration_journal'));

        file_put_contents($path, $sql);
        self::assertStringContainsString('Applied 000002-create-invoices.', $this->runCommand('migrate'));
        self::assertSame('invoices', $connection->fetchOne("SELECT to_regclass('invoices')::text"));
        self::assertSame(0, (int) $connection->fetchOne('SELECT invoice_count()'));
        file_put_contents($path, "\n", FILE_APPEND);
        self::assertStringContainsString('000002-create-invoices" has been modified', $this->runCommand(
            'migrate',
            expectedExit: 1,
        ));
        new Filesystem()->remove(dirname($path));
        self::assertStringContainsString('does not match the versioned', $this->runCommand('status', expectedExit: 1));
    }

    private function runCommand(string $name, int $expectedExit = 0): string
    {
        $result = $this->console->run(['command' => 'ultra:schema:' . $name]);
        $output = $this->console->getDisplay();
        self::assertSame($expectedExit, $result, $output);

        return $output;
    }

    private function file(string $id, string $file): string
    {
        return $this->directory . '/migrations/schema/' . $id . '/' . $file;
    }

    private static function table(string $name): TableDefinition
    {
        return new TableDefinition(
            $name,
            columns: [new ColumnDefinition('id', type: ColumnType::Text)],
            primaryKey: ['id'],
        );
    }
}
