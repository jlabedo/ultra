<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Ultra\Schema\Operations\AddIndex;
use Ultra\Schema\Operations\CreateTable;
use Ultra\Schema\PostgresSqlGenerator;
use Ultra\Schema\TableDefinition;

abstract class MigrationTestCase extends TestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to verify executable schema migrations.');
        }
        $parameters = new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql'])->parse($dsn);
        // PDO drains every result when an operation contains DDL followed by comments.
        $parameters['driver'] = 'pdo_pgsql';
        $this->connection = DriverManager::getConnection($parameters);
        $this->connection->beginTransaction();
        $schema = 'ultra_schema_guarantees_' . bin2hex(random_bytes(6));
        $this->connection->executeStatement('CREATE SCHEMA ' . $schema);
        $this->connection->executeStatement('SET LOCAL search_path TO ' . $schema . ', public');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            if ($this->connection->getTransactionNestingLevel() > 0) {
                $this->connection->rollBack();
            }
            $this->connection->close();
        }
        parent::tearDown();
    }

    protected function create(TableDefinition $table): void
    {
        // New-table index derivation is incomplete; supply the public operations explicitly.
        $this->execute([
            new CreateTable($table),
            ...array_map(static fn($index) => new AddIndex($table->name, $index), $table->indexes),
        ]);
    }

    /** @param list<\Ultra\Schema\Operations\SchemaOperation> $operations */
    protected function execute(array $operations): void
    {
        foreach (new PostgresSqlGenerator()->generate($operations) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }
}
