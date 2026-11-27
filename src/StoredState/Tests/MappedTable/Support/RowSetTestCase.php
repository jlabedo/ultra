<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\Schema\SchemaRowSet;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SqlType;
use Ultra\Schema\TableDefinition;
use Ultra\Testing\PostgresConnectionSupport;

abstract class RowSetTestCase extends TestCase
{
    /** @var list<RowSetFixture> */
    private array $fixtures = [];

    /** @return iterable<string, array{string}> */
    public static function backends(): iterable
    {
        yield 'in memory' => ['memory'];
        yield 'Postgres' => ['postgres'];
    }

    /** @param list<string> $keyColumns @param array<string, ColumnDefinition|ColumnType|SqlType|string> $columnTypes */
    protected function fixture(
        string $backend = 'memory',
        array $keyColumns = ['id'],
        array $columnTypes = [],
        bool $allowAbsentObservations = true,
        Connection|InMemoryRowDatabase|null $storage = null,
        ?MappedTableAdapter $adapter = null,
    ): RowSetFixture {
        if (null === $storage && 'postgres' === $backend) {
            $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
            if (false === $dsn || '' === $dsn) {
                self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run the Postgres guarantee cases.');
            }
            $storage = PostgresConnectionSupport::sharedConnection($dsn);
        }
        $storage ??= new InMemoryRowDatabase();
        $fixture = new RowSetFixture(
            $storage,
            'ultra_guarantees_' . bin2hex(random_bytes(4)),
            $keyColumns,
            $columnTypes,
            $allowAbsentObservations,
            $adapter,
        );
        $this->fixtures[] = $fixture;
        return $fixture;
    }

    /** @return array{RowSetFixture, MappedRowSet} */
    protected function ownedRows(string $backend): array
    {
        $keys = ['tenant', 'owner', 'id'];
        $fixture = $this->fixture($backend, $keys);

        $schema = new SchemaDefinition([
            new TableDefinition(
                $fixture->tableName,
                columns: [
                    new ColumnDefinition('tenant', type: ColumnType::Text),
                    new ColumnDefinition('owner', type: ColumnType::Text),
                    new ColumnDefinition('id', type: ColumnType::Text),
                    new ColumnDefinition('status', type: ColumnType::Text, nullable: true),
                    new ColumnDefinition('note', type: ColumnType::Text, nullable: true),
                ],
                primaryKey: $keys,
            ),
        ]);
        $rows = $fixture->adapter->compileRowSet(SchemaRowSet::fromTable(
            $schema,
            $fixture->tableName,
            ownerColumns: ['tenant', 'owner'],
        ))();

        return [$fixture, $rows];
    }

    protected function stock(string $backend): RowSetFixture
    {
        return $this->fixture($backend, columnTypes: [
            'units' => new ColumnDefinition('units', type: ColumnType::Int32),
            'event_count' => new ColumnDefinition('event_count', type: ColumnType::Int64),
        ]);
    }

    protected function tearDown(): void
    {
        try {
            foreach (array_reverse($this->fixtures) as $fixture) {
                $fixture->close();
            }
        } finally {
            $this->fixtures = [];
            parent::tearDown();
        }
    }
}
