<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests\Support;

use Doctrine\DBAL\Connection;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\MappedTablePolicy;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\PostgresMappedColumnType;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\MappedTable\SchemaMappedRowSet;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\UnitOfWork\UnitOfWork;

/** Storage setup and independent committed reads; assertions stay in the guarantee suites. */
final class RowSetFixture
{
    public MappedRowSet $rows {
        get => $this->defaultRows ??= $this->newRows();
    }

    private ?MappedRowSet $defaultRows = null;
    public readonly MappedTableAdapter $adapter;
    /** @var \WeakMap<MappedRowSet, MappedTableUnitOfWork> */
    private \WeakMap $units;

    /**
     * @param list<string> $keyColumns
     * @param array<string, PostgresMappedColumnType> $columnTypes
     */
    public function __construct(
        public readonly Connection|InMemoryRowDatabase $storage,
        public readonly string $tableName,
        private readonly array $keyColumns = ['id'],
        private readonly array $columnTypes = [],
        bool $allowAbsentObservations = true,
        ?MappedTableAdapter $adapter = null,
    ) {
        $tablePolicies = [$tableName => new MappedTablePolicy($allowAbsentObservations)];
        if ($storage instanceof Connection) {
            $columns = array_fill_keys($keyColumns, 'TEXT NOT NULL');
            $columns += [
                'status' => 'TEXT',
                'note' => 'TEXT',
                'version_a' => 'TEXT',
                'version_b' => 'TEXT',
                'tags' => 'TEXT[]',
                'payload' => 'JSONB',
                'expires_on' => 'DATE',
                'active' => 'BOOLEAN',
            ];
            foreach ($columnTypes as $column => $type) {
                $columns[$column] = $type->value;
            }
            $definitions = [];
            foreach ($columns as $column => $type) {
                $definitions[] = $storage->quoteSingleIdentifier($column) . ' ' . $type;
            }
            $definitions[] =
                'PRIMARY KEY (' . implode(', ', array_map($storage->quoteSingleIdentifier(...), $keyColumns)) . ')';
            $storage->executeStatement(
                'CREATE TABLE '
                . $storage->quoteSingleIdentifier($tableName)
                . ' ('
                . implode(', ', $definitions)
                . ')',
            );
            $this->adapter = $adapter ?? new PostgresMappedTableAdapter($storage, $tablePolicies);
        } else {
            $this->adapter = $adapter ?? new InMemoryMappedTableAdapter($storage, $tablePolicies);
        }
        $this->units = new \WeakMap();
    }

    public function newRows(?MappedTableAdapter $adapter = null): MappedRowSet
    {
        $columns = [];
        foreach ($this->keyColumns as $key) {
            $columns[$key] = new ColumnDefinition($key, type: ColumnType::Text);
        }
        $columns += [
            'status' => new ColumnDefinition('status', type: ColumnType::Text, nullable: true),
            'note' => new ColumnDefinition('note', type: ColumnType::Text, nullable: true),
            'version_a' => new ColumnDefinition('version_a', type: ColumnType::Text, nullable: true),
            'version_b' => new ColumnDefinition('version_b', type: ColumnType::Text, nullable: true),
            'tags' => new ColumnDefinition('tags', type: 'text[]', nullable: true),
            'payload' => new ColumnDefinition('payload', type: 'jsonb', nullable: true),
            'expires_on' => new ColumnDefinition('expires_on', type: 'date', nullable: true),
            'active' => new ColumnDefinition('active', type: ColumnType::Boolean, nullable: true),
        ];
        $schema = new SchemaDefinition([
            new TableDefinition($this->tableName, columns: array_values($columns), primaryKey: $this->keyColumns),
        ]);

        return new SchemaMappedRowSet(
            $this->tableName,
            representations: ['tags' => 'array', 'payload' => 'array', 'expires_on' => 'string'],
            postgresColumnTypes: $this->columnTypes,
        )->compileFor($adapter ?? $this->adapter, $schema)();
    }

    /** @param array<string, mixed> $row */
    public function seed(array $row): void
    {
        $key = new MappedKey(array_intersect_key($row, array_flip($this->keyColumns)));
        if ($this->storage instanceof InMemoryRowDatabase) {
            $this->storage->writeRowByKey($this->tableName, $key, array_diff_key($row, $key->values));
        } else {
            $this->storage->delete($this->tableName, $key->values);
            $this->storage->insert($this->tableName, $row);
        }
    }

    public function delete(int|string|MappedKey $key): void
    {
        $key = $key instanceof MappedKey ? $key : new MappedKey(['id' => $key]);
        if ($this->storage instanceof InMemoryRowDatabase) {
            $this->storage->deleteRowByKey($this->tableName, $key);
        } else {
            $this->storage->delete($this->tableName, $key->values);
        }
    }

    /** @param list<string> $columns @return array<string, mixed>|false */
    public function stored(
        int|string|MappedKey $key,
        array $columns = ['status', 'note', 'version_a', 'version_b'],
    ): array|false {
        $key = $key instanceof MappedKey ? $key : new MappedKey(['id' => $key]);
        if ($this->storage instanceof InMemoryRowDatabase) {
            return $this->storage->rowByKey($this->tableName, $key, $columns);
        }
        $where = array_map(
            fn(string $column): string => $this->storage->quoteSingleIdentifier($column) . ' = ?',
            $key->columns(),
        );
        $row = $this->storage->fetchAssociative(
            'SELECT '
                . implode(', ', array_map($this->storage->quoteSingleIdentifier(...), $columns))
                . ' FROM '
                . $this->storage->quoteSingleIdentifier($this->tableName)
                . ' WHERE '
                . implode(' AND ', $where),
            array_values($key->values),
        );
        if (false !== $row) {
            foreach ($this->columnTypes as $column => $type) {
                if (!array_key_exists($column, $row)) {
                    continue;
                }

                $row[$column] = $type->decode($row[$column]);
            }
        }
        return $row;
    }

    public function unit(?MappedRowSet $rows = null): MappedTableUnitOfWork
    {
        $rows ??= $this->rows;

        return $this->units[$rows] ??= new MappedTableUnitOfWork([$this->tableName => $rows], $this->adapter);
    }

    public function flush(UnitOfWork|MappedRowSet|null $rows = null): void
    {
        $unit = $rows instanceof UnitOfWork ? $rows : $this->unit($rows);
        $this->storage->beginTransaction();
        try {
            $unit->flush();
            $this->storage->commit();
        } catch (\Throwable $exception) {
            $this->storage->rollBack();
            throw $exception;
        }
    }

    public function connection(): Connection
    {
        if (!$this->storage instanceof Connection) {
            throw new \LogicException('This scenario requires Postgres.');
        }
        return $this->storage;
    }

    public function close(): void
    {
        if ($this->storage instanceof Connection) {
            PostgresConnectionSupport::resetSharedConnection($this->storage);
            $this->storage->executeStatement(
                'DROP TABLE IF EXISTS ' . $this->storage->quoteSingleIdentifier($this->tableName),
            );
        } else {
            while ($this->storage->isTransactionActive()) {
                $this->storage->rollBack();
            }
        }
    }
}
