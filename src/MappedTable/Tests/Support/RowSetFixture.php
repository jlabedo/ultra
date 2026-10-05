<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests\Support;

use Doctrine\DBAL\Connection;
use Ultra\MappedTable\InMemoryMappedRowSetAdapterFactory;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetAdapterFactory;
use Ultra\MappedTable\PostgresMappedColumnType;
use Ultra\MappedTable\PostgresMappedRowSetAdapterFactory;
use Ultra\Testing\PostgresTestSupport;

/** Storage setup and independent committed reads; assertions stay in the guarantee suites. */
final class RowSetFixture
{
    public readonly MappedRowSet $rows;
    private readonly MappedRowSetAdapterFactory $factory;

    /**
     * @param list<string> $keyColumns
     * @param array<string, PostgresMappedColumnType> $columnTypes
     */
    public function __construct(
        public readonly Connection|InMemoryRowDatabase $storage,
        public readonly string $tableName,
        private readonly array $keyColumns = ['id'],
        ?MappedKey $scope = null,
        private readonly array $columnTypes = [],
    ) {
        if ($storage instanceof Connection) {
            $columns = array_fill_keys($keyColumns, 'TEXT NOT NULL');
            $columns += ['status' => 'TEXT', 'note' => 'TEXT', 'version_a' => 'TEXT', 'version_b' => 'TEXT', 'tags' => 'TEXT[]', 'payload' => 'JSONB', 'expires_on' => 'DATE', 'active' => 'BOOLEAN'];
            foreach ($columnTypes as $column => $type) {
                $columns[$column] = $type->sqlType;
            }
            $definitions = [];
            foreach ($columns as $column => $type) {
                $definitions[] = $storage->quoteSingleIdentifier($column).' '.$type;
            }
            $definitions[] = 'PRIMARY KEY ('.implode(', ', array_map($storage->quoteSingleIdentifier(...), $keyColumns)).')';
            $storage->executeStatement('CREATE TABLE '.$storage->quoteSingleIdentifier($tableName).' ('.implode(', ', $definitions).')');
            $this->factory = new PostgresMappedRowSetAdapterFactory($storage, [$tableName => $columnTypes]);
        } else {
            $this->factory = new InMemoryMappedRowSetAdapterFactory($storage);
        }
        $this->rows = $this->view($scope);
    }

    public function view(?MappedKey $scope = null): MappedRowSet
    {
        return new MappedRowSet(null === $scope
            ? $this->factory->create($this->tableName, $this->keyColumns)
            : $this->factory->createScoped($this->tableName, $this->keyColumns, $scope));
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
    public function stored(int|string|MappedKey $key, array $columns = ['status', 'note', 'version_a', 'version_b']): array|false
    {
        $key = $key instanceof MappedKey ? $key : new MappedKey(['id' => $key]);
        if ($this->storage instanceof InMemoryRowDatabase) {
            return $this->storage->rowByKey($this->tableName, $key, $columns);
        }
        $where = array_map(fn (string $column): string => $this->storage->quoteSingleIdentifier($column).' = ?', $key->columns());
        $row = $this->storage->fetchAssociative(
            'SELECT '.implode(', ', array_map($this->storage->quoteSingleIdentifier(...), $columns)).' FROM '.$this->storage->quoteSingleIdentifier($this->tableName).' WHERE '.implode(' AND ', $where),
            array_values($key->values),
        );
        if (false !== $row) {
            foreach ($this->columnTypes as $column => $type) {
                if (array_key_exists($column, $row)) {
                    $row[$column] = $type->decode($row[$column]);
                }
            }
        }
        return $row;
    }

    public function flush(?MappedRowSet $rows = null): void
    {
        $this->storage->beginTransaction();
        try {
            ($rows ?? $this->rows)->flush();
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
            PostgresTestSupport::resetSharedConnection($this->storage);
            $this->storage->executeStatement('DROP TABLE IF EXISTS '.$this->storage->quoteSingleIdentifier($this->tableName));
        } else {
            while ($this->storage->isTransactionActive()) {
                $this->storage->rollBack();
            }
        }
    }
}
