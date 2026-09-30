<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Doctrine\DBAL\Connection;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

final readonly class PostgresMappedRowSetAdapter implements MappedRowSetAdapter
{
    /**
     * @param list<string>                                $configuredKeyColumns
     * @param array<string, PostgresMappedColumnType>     $columnTypes
     */
    public function __construct(
        private Connection $connection,
        private string $tableName,
        private array $configuredKeyColumns = ['id'],
        private array $columnTypes = [],
        private ?MappedKey $configuredScope = null,
    ) {
        if ([] === $configuredKeyColumns || count($configuredKeyColumns) !== count(array_unique($configuredKeyColumns))) {
            throw new \InvalidArgumentException('Mapped row-set key columns must be non-empty and unique.');
        }
        foreach ($configuredKeyColumns as $column) {
            if ('' === $column) {
                throw new \InvalidArgumentException('Mapped row-set key column names must be non-empty.');
            }
        }
        if (null !== $configuredScope && [] !== array_diff($configuredScope->columns(), $configuredKeyColumns)) {
            throw new \InvalidArgumentException('Mapped row-set scope columns must belong to its key.');
        }
    }

    public function keyColumns(): array
    {
        $columns = $this->configuredKeyColumns;
        sort($columns, SORT_STRING);

        return $columns;
    }

    public function tableName(): string
    {
        return $this->tableName;
    }

    public function lock(MappedKey $key): void
    {
        $this->assertKey($key);
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Manual row locks require an active transaction.');
        }

        // Keep manual guards separate from the automatic locks used to protect absent rows and row sets.
        $this->connection->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))',
            [$this->tableName.':manual', $key->fingerprint()],
        );
    }

    public function scope(): ?MappedKey
    {
        return $this->configuredScope;
    }

    public function row(MappedKey $key, array $columns = []): array|false
    {
        $this->assertKey($key);
        $quotedColumns = [] === $columns
            ? ['*']
            : array_map($this->connection->quoteSingleIdentifier(...), array_values(array_unique($columns)));

        $row = $this->connection->fetchAssociative(sprintf(
            'SELECT %s FROM %s WHERE %s',
            implode(', ', $quotedColumns),
            $this->connection->quoteSingleIdentifier($this->tableName),
            implode(' AND ', $this->keyWhereClauses()),
        ), $this->encodedKeyValues($key));

        return false === $row ? false : $this->decodeRow($row);
    }

    public function rows(array $columns = []): array
    {
        $selected = [] === $columns ? ['*'] : array_values(array_unique([...$this->keyColumns(), ...$columns]));
        $quoted = '*' === $selected[0]
            ? ['*']
            : array_map($this->connection->quoteSingleIdentifier(...), $selected);

        $rows = $this->connection->fetchAllAssociative(sprintf(
            'SELECT %s FROM %s%s ORDER BY %s',
            implode(', ', $quoted),
            $this->connection->quoteSingleIdentifier($this->tableName),
            $this->scopeWhereSql(),
            implode(', ', array_map($this->connection->quoteSingleIdentifier(...), $this->keyColumns())),
        ), $this->scopeParams());

        $mapped = [];
        foreach ($rows as $row) {
            $row = $this->decodeRow($row);
            $keyValues = [];
            foreach ($this->keyColumns() as $column) {
                $value = $row[$column] ?? null;
                if (!is_int($value) && !is_string($value)) {
                    throw new \LogicException(sprintf('Table "%s" returned no scalar key column "%s".', $this->tableName, $column));
                }
                $keyValues[$column] = $value;
                unset($row[$column]);
            }
            $mapped[] = new MappedRow(new MappedKey($keyValues), $row);
        }

        return $mapped;
    }

    /**
     * Flushes the plan inside the caller's transaction: locks the keys of rows expected absent or possibly created,
     * checks the row-key expectations and check-only rows, then applies the writes.
     */
    public function apply(RowSetUpdatePlan $plan): void
    {
        $writes = [];
        $checks = [];
        foreach ($plan->rowUpdates as $update) {
            $this->assertKey($update->key);
            if ($update->writes()) {
                $writes[] = $update;
            } else {
                $checks[] = $update;
            }
        }

        $this->lockAbsentKeys($checks, $writes);
        $this->assertExpectedRowKeys($plan->expectedRowKeys, $plan->ignoredRowKeys);
        $this->checkRows($checks);

        foreach ($this->groupConsecutive($writes) as $batch) {
            if (1 === count($batch)) {
                $this->flushOne($batch[0]);
                continue;
            }

            match ($batch[0]->mode) {
                RowUpdatePlan::MODE_DELETE => $this->flushBatchDelete($batch),
                RowUpdatePlan::MODE_INSERT => $this->flushBatchInsert($batch),
                RowUpdatePlan::MODE_REPLACE => $this->flushBatchReplace($batch),
                RowUpdatePlan::MODE_UPSERT => $this->flushBatchUpsert($batch),
                default => array_map($this->flushOne(...), $batch),
            };
        }
    }

    private function flushOne(RowUpdatePlan $update): void
    {
        match ($update->mode) {
            RowUpdatePlan::MODE_DELETE => $this->flushDelete($update),
            RowUpdatePlan::MODE_INSERT => $this->flushInsert($update),
            RowUpdatePlan::MODE_REPLACE => $this->flushReplace($update),
            RowUpdatePlan::MODE_UPSERT => $this->flushUpsert($update),
            RowUpdatePlan::MODE_UPDATE => $this->flushUpdate($update),
            default => throw new \LogicException(sprintf('Unsupported row update mode "%s".', $update->mode)),
        };
    }

    /** @param list<RowUpdatePlan> $updates @return list<list<RowUpdatePlan>> */
    private function groupConsecutive(array $updates): array
    {
        $groups = [];
        $currentKey = null;
        $current = [];
        foreach ($updates as $update) {
            $key = match ($update->mode) {
                RowUpdatePlan::MODE_INSERT, RowUpdatePlan::MODE_UPSERT => $update->mode.':'.implode(',', array_keys($update->setColumns)),
                RowUpdatePlan::MODE_DELETE => [] === $update->expectedColumns ? 'delete' : 'delete:'.$update->key->fingerprint(),
                RowUpdatePlan::MODE_REPLACE => [] === $update->expectedColumns ? 'replace:'.implode(',', array_keys($update->setColumns)) : 'replace:'.$update->key->fingerprint(),
                default => $update->mode.':'.$update->key->fingerprint(),
            };
            if ($key !== $currentKey) {
                if ([] !== $current) {
                    $groups[] = $current;
                }
                $currentKey = $key;
                $current = [];
            }
            $current[] = $update;
        }
        if ([] !== $current) {
            $groups[] = $current;
        }

        return $groups;
    }

    /** @param list<RowUpdatePlan> $updates */
    private function flushBatchDelete(array $updates): void
    {
        $branches = [];
        $params = [];
        foreach ($updates as $update) {
            $branches[] = '('.implode(' AND ', $this->keyWhereClauses()).')';
            array_push($params, ...$this->encodedKeyValues($update->key));
        }
        $affected = $this->connection->executeStatement(sprintf(
            'DELETE FROM %s WHERE %s',
            $this->connection->quoteSingleIdentifier($this->tableName),
            implode(' OR ', $branches),
        ), $params);
        if ($affected !== count($updates)) {
            throw new ConcurrencyException(sprintf('Batch delete failed for table "%s": expected %d deletions, got %d.', $this->tableName, count($updates), $affected));
        }
    }

    /** @param list<RowUpdatePlan> $updates */
    private function flushBatchInsert(array $updates): void
    {
        [$columns] = $this->insertValues($updates[0]);
        $rowPlaceholder = '('.implode(', ', array_map($this->placeholder(...), $columns)).')';
        $params = [];
        foreach ($updates as $update) {
            [, $rowParams] = $this->insertValues($update);
            array_push($params, ...$rowParams);
        }
        $affected = $this->connection->executeStatement(sprintf(
            'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) DO NOTHING',
            $this->connection->quoteSingleIdentifier($this->tableName),
            implode(', ', array_map($this->connection->quoteSingleIdentifier(...), $columns)),
            implode(', ', array_fill(0, count($updates), $rowPlaceholder)),
            $this->conflictTargetSql(),
        ), $params);
        if ($affected !== count($updates)) {
            throw new ConcurrencyException(sprintf('Batch insert failed for table "%s": %d rows already existed.', $this->tableName, count($updates) - $affected));
        }
    }

    /** @param list<RowUpdatePlan> $updates */
    private function flushBatchReplace(array $updates): void
    {
        $this->flushBatchDelete($updates);
        $this->flushBatchInsert($updates);
    }

    /** @param list<RowUpdatePlan> $updates */
    private function flushBatchUpsert(array $updates): void
    {
        [$columns] = $this->insertValues($updates[0]);
        $rowPlaceholder = '('.implode(', ', array_map($this->placeholder(...), $columns)).')';
        $params = [];
        foreach ($updates as $update) {
            [, $rowParams] = $this->insertValues($update);
            array_push($params, ...$rowParams);
        }
        if ([] === $updates[0]->setColumns) {
            $action = 'DO NOTHING';
        } else {
            $action = 'DO UPDATE SET '.implode(', ', array_map(function (string $column): string {
                $quoted = $this->connection->quoteSingleIdentifier($column);

                return sprintf('%1$s = EXCLUDED.%1$s', $quoted);
            }, array_keys($updates[0]->setColumns)));
        }
        $this->connection->executeStatement(sprintf(
            'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) %s',
            $this->connection->quoteSingleIdentifier($this->tableName),
            implode(', ', array_map($this->connection->quoteSingleIdentifier(...), $columns)),
            implode(', ', array_fill(0, count($updates), $rowPlaceholder)),
            $this->conflictTargetSql(),
            $action,
        ), $params);
    }

    /**
     * A row cannot be locked before it exists, so rows expected absent and rows a write may create (insert, upsert)
     * lock their key with a transaction-scoped advisory lock: an absent check then holds back a concurrent creation of
     * the row until its transaction ends, and the other way around. Keys are locked in sorted order, in one statement.
     *
     * @param list<RowUpdatePlan> $checks
     * @param list<RowUpdatePlan> $writes
     */
    private function lockAbsentKeys(array $checks, array $writes): void
    {
        $lockKeys = [];
        foreach ($checks as $check) {
            if (RowUpdatePlan::EXPECTED_ABSENT === $check->expectedPresence) {
                $lockKeys[] = $check->key->fingerprint();
            }
        }
        $absentChecks = count($lockKeys);
        foreach ($writes as $write) {
            if (RowUpdatePlan::MODE_INSERT === $write->mode || RowUpdatePlan::MODE_UPSERT === $write->mode) {
                $lockKeys[] = $write->key->fingerprint();
            }
        }

        if ([] === $lockKeys) {
            return;
        }
        if (!$this->connection->isTransactionActive()) {
            if (0 === $absentChecks) {
                // An autocommitted write holds no lock worth taking.
                return;
            }

            throw new \LogicException(sprintf('Absent row expectations for table "%s" require an active transaction.', $this->tableName));
        }

        $lockKeys = array_values(array_unique($lockKeys));
        sort($lockKeys, SORT_STRING);
        // jsonb_array_elements_text() yields the keys in array order, so the locks are taken in sorted order.
        $this->connection->executeQuery(
            'SELECT count(pg_advisory_xact_lock(hashtext(?), hashtext(lock_key))) FROM jsonb_array_elements_text(?::jsonb) AS locks(lock_key)',
            [$this->tableName.':row', json_encode($lockKeys, JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * Checks the rows that are only expected: present rows with their expected columns, locked FOR SHARE until the
     * transaction ends, and absent rows, whose keys lockAbsentKeys() holds.
     *
     * @param list<RowUpdatePlan> $checks
     */
    private function checkRows(array $checks): void
    {
        $present = array_values(array_filter($checks, static fn (RowUpdatePlan $check): bool => RowUpdatePlan::EXPECTED_ABSENT !== $check->expectedPresence));
        if ([] !== $present) {
            $branches = [];
            $params = [];
            foreach ($present as $check) {
                [$where, $whereParams] = $this->conditionalWhere($check);
                $branches[] = '('.implode(' AND ', $where).')';
                array_push($params, ...$whereParams);
            }
            $matched = $this->connection->fetchAllAssociative(sprintf(
                'SELECT 1 FROM %s WHERE %s FOR SHARE',
                $this->connection->quoteSingleIdentifier($this->tableName),
                implode(' OR ', $branches),
            ), $params);
            if (count($matched) !== count($present)) {
                throw new ConcurrencyException(sprintf('Row expectation failed for table "%s": %d of %d expected rows changed.', $this->tableName, count($present) - count($matched), count($present)));
            }
        }

        $absent = array_values(array_filter($checks, static fn (RowUpdatePlan $check): bool => RowUpdatePlan::EXPECTED_ABSENT === $check->expectedPresence));
        if ([] !== $absent) {
            $params = [];
            foreach ($absent as $check) {
                array_push($params, ...$this->encodedKeyValues($check->key));
            }
            $existing = (int) $this->connection->fetchOne(sprintf(
                'SELECT count(*) FROM %s WHERE %s',
                $this->connection->quoteSingleIdentifier($this->tableName),
                implode(' OR ', array_fill(0, count($absent), '('.implode(' AND ', $this->keyWhereClauses()).')')),
            ), $params);
            if (0 !== $existing) {
                throw new ConcurrencyException(sprintf('Row expectation failed for table "%s": %d expected absent rows exist.', $this->tableName, $existing));
            }
        }
    }

    /**
     * @param list<MappedKey>|null $expected
     * @param list<MappedKey>      $ignored
     */
    private function assertExpectedRowKeys(?array $expected, array $ignored): void
    {
        if (null === $expected) {
            return;
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException(sprintf('Row-set expectations for table "%s" require an active transaction.', $this->tableName));
        }

        $this->connection->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))',
            [$this->tableName, json_encode([$this->keyColumns(), $this->configuredScope?->values], JSON_THROW_ON_ERROR)],
        );

        $actual = array_map(static fn (MappedRow $row): string => $row->key->fingerprint(), $this->rows());
        $expected = array_map(static fn (MappedKey $key): string => $key->fingerprint(), $expected);
        $actual = array_values(array_diff($actual, array_map(static fn (MappedKey $key): string => $key->fingerprint(), $ignored)));
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        if ($actual !== $expected) {
            throw new ConcurrencyException(sprintf('Row-set expectation failed for table "%s": row keys changed.', $this->tableName));
        }
    }

    private function flushDelete(RowUpdatePlan $update): void
    {
        [$where, $params] = $this->conditionalWhere($update);
        $affected = $this->connection->executeStatement(sprintf(
            'DELETE FROM %s WHERE %s',
            $this->connection->quoteSingleIdentifier($this->tableName),
            implode(' AND ', $where),
        ), $params);

        if (1 !== $affected) {
            throw $this->concurrency('Conditional delete failed', $update);
        }
    }

    private function flushInsert(RowUpdatePlan $update): void
    {
        [$columns, $params] = $this->insertValues($update);
        $affected = $this->connection->executeStatement(sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO NOTHING',
            $this->connection->quoteSingleIdentifier($this->tableName),
            implode(', ', array_map($this->connection->quoteSingleIdentifier(...), $columns)),
            implode(', ', array_map($this->placeholder(...), $columns)),
            $this->conflictTargetSql(),
        ), $params);

        if (1 !== $affected) {
            throw $this->concurrency('Insert failed because the row already exists', $update);
        }
    }

    private function flushReplace(RowUpdatePlan $update): void
    {
        $this->flushDelete($update);
        $this->flushInsert($update);
    }

    private function flushUpsert(RowUpdatePlan $update): void
    {
        [$columns, $params] = $this->insertValues($update);
        $quotedColumns = implode(', ', array_map($this->connection->quoteSingleIdentifier(...), $columns));
        $placeholders = implode(', ', array_map($this->placeholder(...), $columns));

        if ([] === $update->setColumns) {
            $action = 'DO NOTHING';
        } else {
            $assignments = array_map(function (string $column): string {
                $quoted = $this->connection->quoteSingleIdentifier($column);

                return sprintf('%1$s = EXCLUDED.%1$s', $quoted);
            }, array_keys($update->setColumns));
            $action = 'DO UPDATE SET '.implode(', ', $assignments);
        }

        $this->connection->executeStatement(sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) %s',
            $this->connection->quoteSingleIdentifier($this->tableName),
            $quotedColumns,
            $placeholders,
            $this->conflictTargetSql(),
            $action,
        ), $params);
    }

    private function flushUpdate(RowUpdatePlan $update): void
    {
        $set = [];
        $params = [];
        foreach ($update->setColumns as $column => $value) {
            $set[] = sprintf('%s = %s', $this->connection->quoteSingleIdentifier($column), $this->placeholder($column));
            $params[] = $this->encodeColumnValue($column, $value);
        }
        [$where, $whereParams] = $this->conditionalWhere($update);
        array_push($params, ...$whereParams);

        $affected = $this->connection->executeStatement(sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->connection->quoteSingleIdentifier($this->tableName),
            implode(', ', $set),
            implode(' AND ', $where),
        ), $params);

        if (1 !== $affected) {
            throw $this->concurrency('Conditional update failed', $update);
        }
    }

    /** @return array{list<string>, list<mixed>} */
    private function conditionalWhere(RowUpdatePlan $update): array
    {
        $where = $this->keyWhereClauses();
        $params = $this->encodedKeyValues($update->key);
        foreach ($update->expectedColumns as $column => $value) {
            $where[] = sprintf('%s IS NOT DISTINCT FROM %s', $this->connection->quoteSingleIdentifier($column), $this->placeholder($column));
            $params[] = $this->encodeColumnValue($column, $value);
        }

        return [$where, $params];
    }

    /** @return array{list<string>, list<mixed>} */
    private function insertValues(RowUpdatePlan $update): array
    {
        $columns = $this->keyColumns();
        $params = $this->encodedKeyValues($update->key);
        foreach ($update->setColumns as $column => $value) {
            $columns[] = $column;
            $params[] = $this->encodeColumnValue($column, $value);
        }

        return [$columns, $params];
    }

    /** @return list<string> */
    private function keyWhereClauses(): array
    {
        return array_map(
            fn (string $column): string => sprintf('%s = %s', $this->connection->quoteSingleIdentifier($column), $this->placeholder($column)),
            $this->keyColumns(),
        );
    }

    /** @return list<mixed> */
    private function encodedKeyValues(MappedKey $key): array
    {
        return array_map(fn (string $column): mixed => $this->encodeColumnValue($column, $key->value($column)), $this->keyColumns());
    }

    private function scopeWhereSql(): string
    {
        if (null === $this->configuredScope) {
            return '';
        }

        return ' WHERE '.implode(' AND ', array_map(
            fn (string $column): string => sprintf('%s = %s', $this->connection->quoteSingleIdentifier($column), $this->placeholder($column)),
            $this->configuredScope->columns(),
        ));
    }

    /** @return list<mixed> */
    private function scopeParams(): array
    {
        if (null === $this->configuredScope) {
            return [];
        }

        return array_map(
            fn (string $column): mixed => $this->encodeColumnValue($column, $this->configuredScope->value($column)),
            $this->configuredScope->columns(),
        );
    }

    private function conflictTargetSql(): string
    {
        return implode(', ', array_map($this->connection->quoteSingleIdentifier(...), $this->keyColumns()));
    }

    private function placeholder(string $column): string
    {
        $type = $this->columnTypes[$column] ?? null;

        return null === $type ? '?' : sprintf('?::%s', $type->sqlType);
    }

    private function encodeColumnValue(string $column, mixed $value): mixed
    {
        $type = $this->columnTypes[$column] ?? null;
        if (null !== $type) {
            return $type->encode($value);
        }

        // PDO binds false as an empty string, which Postgres rejects for boolean columns.
        return is_bool($value) ? ($value ? 'true' : 'false') : $value;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function decodeRow(array $row): array
    {
        foreach ($this->columnTypes as $column => $type) {
            if (array_key_exists($column, $row)) {
                $row[$column] = $type->decode($row[$column]);
            }
        }

        return $row;
    }

    private function assertKey(MappedKey $key): void
    {
        $key->assertColumns($this->keyColumns());
        if (null !== $this->configuredScope && !$key->matches($this->configuredScope)) {
            throw new \InvalidArgumentException('Mapped key does not belong to adapter scope.');
        }
    }

    private function concurrency(string $message, RowUpdatePlan $update): ConcurrencyException
    {
        return new ConcurrencyException(sprintf('%s for table "%s" and key %s.', $message, $this->tableName, $update->key));
    }
}
