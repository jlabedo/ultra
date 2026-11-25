<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests\Support;

use Doctrine\DBAL\Connection;
use Ultra\MappedTable\ColumnCodec;
use Ultra\MappedTable\ColumnCodecs;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\MappedTablePolicy;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\Operations\CreateTable;
use Ultra\Schema\PostgresSqlGenerator;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SqlType;
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
    private readonly TableDefinition $definition;
    /** @var \WeakMap<MappedRowSet, MappedTableUnitOfWork> */
    private \WeakMap $units;

    /**
     * @param list<string> $keyColumns
     * @param array<string, ColumnDefinition|ColumnType|SqlType|string> $columnTypes
     */
    public function __construct(
        public readonly Connection|InMemoryRowDatabase $storage,
        public readonly string $tableName,
        private readonly array $keyColumns = ['id'],
        array $columnTypes = [],
        bool $allowAbsentObservations = true,
        ?MappedTableAdapter $adapter = null,
    ) {
        $types = array_replace(
            array_fill_keys($keyColumns, ColumnType::Text)
            + [
                'status' => ColumnType::Text,
                'note' => ColumnType::Text,
                'version_a' => ColumnType::Text,
                'version_b' => ColumnType::Text,
                'tags' => 'text[]',
                'payload' => 'jsonb',
                'expires_on' => 'date',
                'active' => ColumnType::Boolean,
            ],
            $columnTypes,
        );
        $columns = [];
        foreach ($types as $column => $type) {
            $columns[] = $type instanceof ColumnDefinition
                ? $type
                : new ColumnDefinition($column, type: $type, nullable: !in_array($column, $keyColumns, true));
        }
        $this->definition = new TableDefinition($tableName, columns: $columns, primaryKey: $keyColumns);

        $tablePolicies = [$tableName => new MappedTablePolicy($allowAbsentObservations)];
        if ($storage instanceof Connection) {
            foreach (new PostgresSqlGenerator()->generate([new CreateTable($this->definition)]) as $sql) {
                $storage->executeStatement($sql);
            }
            $this->adapter = $adapter ?? new PostgresMappedTableAdapter($storage, $tablePolicies);
        } else {
            $this->adapter = $adapter ?? new InMemoryMappedTableAdapter($storage, $tablePolicies);
        }
        $this->units = new \WeakMap();
    }

    /** @param array<string, ColumnCodec> $codecs */
    public function newRows(?MappedTableAdapter $adapter = null, array $codecs = []): MappedRowSet
    {
        return ($adapter ?? $this->adapter)->compileRowSet(
            new SchemaDefinition([$this->definition]),
            $this->tableName,
            codecs: $codecs,
        )();
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
            foreach ($this->definition->columns as $column) {
                if (array_key_exists($column->name, $row)) {
                    $value = $row[$column->name];
                    $codec = ColumnCodecs::builtin($column->type) ?? throw new \LogicException(
                        'Unsupported test column.',
                    );
                    $row[$column->name] = null === $value ? null : $codec->decode($column->type, $value);
                }
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
