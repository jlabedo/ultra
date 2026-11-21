<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Doctrine\DBAL\Connection;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

final readonly class PostgresMappedRowSetAdapter implements OwnerMappedRowSetAdapter, CoordinatedMappedRowSetAdapter
{
    /** @var list<string> */
    private array $keyColumns;

    /**
     * @param list<string>                                $configuredKeyColumns
     * @param array<string, PostgresMappedColumnType>     $columnTypes
     */
    public function __construct(
        private Connection $connection,
        private string $tableName,
        array $configuredKeyColumns = ['id'],
        private array $columnTypes = [],
        private ?MappedKey $configuredScope = null,
        private MappedTablePolicy $policy = new MappedTablePolicy(),
        private ?PostgresMappedRowSetSql $sql = null,
    ) {
        if (null !== $sql) {
            $this->keyColumns = $configuredKeyColumns;

            return;
        }

        if (
            [] === $configuredKeyColumns
            || count($configuredKeyColumns) !== count(array_unique($configuredKeyColumns))
        ) {
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
        sort($configuredKeyColumns, SORT_STRING);
        $this->keyColumns = $configuredKeyColumns;
    }

    public function keyColumns(): array
    {
        return $this->keyColumns;
    }

    public function scope(): ?MappedKey
    {
        return $this->configuredScope;
    }

    public function row(MappedKey $key, array $columns = []): array|false
    {
        $this->assertKey($key);
        $projection = match (true) {
            [] === $columns => '*',
            $columns === $this->sql?->columnNames => $this->sql->rowColumns,
            default => implode(', ', array_map($this->quoteColumn(...), array_values(array_unique($columns)))),
        };

        $row = $this->connection->fetchAssociative(
            sprintf('SELECT %s FROM %s WHERE %s', $projection, $this->quotedTable(), $this->keyPredicate()),
            $this->encodedKeyValues($key),
        );

        return false === $row ? false : $this->decodeRow($row);
    }

    public function rows(array $columns = []): array
    {
        return $this->readRows($columns, $this->configuredScope);
    }

    public function rowsForOwner(MappedKey $owner, array $columns = []): array
    {
        return $this->readRows($columns, $owner);
    }

    /**
     * @param list<string> $columns
     * @return list<MappedRow>
     */
    private function readRows(array $columns, ?MappedKey $scope): array
    {
        $projection = match (true) {
            [] === $columns => '*',
            $columns === $this->keyColumns => $this->conflictTargetSql(),
            $columns === $this->sql?->nonKeyColumns => $this->sql->rowSetColumns,
            default => implode(', ', array_map(
                $this->quoteColumn(...),
                array_values(array_unique([...$this->keyColumns, ...$columns])),
            )),
        };

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM %s%s ORDER BY %s',
                $projection,
                $this->quotedTable(),
                $this->scopeWhereSql($scope),
                $this->conflictTargetSql(),
            ),
            $this->scopeParams($scope),
        );

        $mapped = [];
        foreach ($rows as $row) {
            $row = $this->decodeRow($row);
            $keyValues = [];
            foreach ($this->keyColumns() as $column) {
                $value = $row[$column] ?? null;
                if (!is_int($value) && !is_string($value)) {
                    throw new \LogicException(sprintf(
                        'Table "%s" returned no scalar key column "%s".',
                        $this->tableName,
                        $column,
                    ));
                }
                $keyValues[$column] = $value;
                unset($row[$column]);
            }
            $mapped[] = new MappedRow(new MappedKey($keyValues), $row);
        }

        return $mapped;
    }

    /**
     * Flushes the plan inside the caller's transaction: protects absent keys when configured,
     * checks the row-key expectations and check-only rows, then applies the writes.
     */
    public function apply(RowSetUpdatePlan $plan): void
    {
        $this->check($plan);
        $this->writeRows(array_values(array_filter(
            $plan->rowUpdates,
            static fn(RowUpdatePlan $row): bool => $row->writes(),
        )));
        $this->deleteExcludedRows($plan);
    }

    public function activeTransactionResource(): object
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Coordinated mapped table flush requires an active transaction.');
        }

        return $this->connection;
    }

    public function check(RowSetUpdatePlan $plan): void
    {
        $this->policy->assertSupported($plan, $this->tableName);

        $writes = [];
        $checks = [];
        foreach ($plan->rowUpdates as $update) {
            $this->assertKey($update->key);
            if ($update->writes()) {
                $writes[] = $update;

                continue;
            }
            $checks[] = $update;
        }

        $this->lockAbsentKeys($checks, $writes);
        $this->assertExpectedRowKeys($plan->expectedRowKeys, $plan->ignoredRowKeys);
        foreach ($plan->ownerCollections as $collection) {
            $this->assertExpectedRowKeys($collection->expectedRowKeys, $collection->ignoredRowKeys, $collection->owner);
        }
        $this->checkRows($checks);
    }

    public function writeRows(array $writes): void
    {
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

    public function deleteExcludedRows(RowSetUpdatePlan $plan): void
    {
        $this->deleteRowsOutside($plan->retainedRowKeys);
        foreach ($plan->ownerCollections as $collection) {
            $this->deleteRowsOutside($collection->retainedRowKeys, $collection->owner);
        }
    }

    /** @param list<MappedKey>|null $keys */
    private function deleteRowsOutside(?array $keys, ?MappedKey $owner = null): void
    {
        if (null === $keys) {
            return;
        }

        $scope = $owner ?? $this->configuredScope;
        $where = $this->scopeWhereClauses($scope);
        $params = $this->scopeParams($scope);
        $retained = [];
        foreach ($keys as $key) {
            $this->assertKey($key);
            $retained[] = '(' . $this->keyPredicate() . ')';
            array_push($params, ...$this->encodedKeyValues($key));
        }
        if ([] !== $retained) {
            $where[] = 'NOT (' . implode(' OR ', $retained) . ')';
        }

        $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s%s',
                $this->quotedTable(),
                [] === $where ? '' : ' WHERE ' . implode(' AND ', $where),
            ),
            $params,
        );
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

    /**
     * @param list<RowUpdatePlan> $updates
     * @return list<list<RowUpdatePlan>>
     */
    private function groupConsecutive(array $updates): array
    {
        $groups = [];
        $currentKey = null;
        $current = [];
        foreach ($updates as $update) {
            $key = match ($update->mode) {
                RowUpdatePlan::MODE_INSERT, RowUpdatePlan::MODE_UPSERT => $update->mode
                    . ':'
                    . implode(',', array_keys($update->setColumns)),
                RowUpdatePlan::MODE_DELETE => [] === $update->expectedColumns
                    ? 'delete'
                    : 'delete:' . $update->key->fingerprint(),
                RowUpdatePlan::MODE_REPLACE => [] === $update->expectedColumns
                    ? 'replace:' . implode(',', array_keys($update->setColumns))
                    : 'replace:' . $update->key->fingerprint(),
                default => $update->mode . ':' . $update->key->fingerprint(),
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
            $branches[] = '(' . $this->keyPredicate() . ')';
            array_push($params, ...$this->encodedKeyValues($update->key));
        }
        $affected = $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE %s', $this->quotedTable(), implode(' OR ', $branches)),
            $params,
        );
        if ($affected !== count($updates)) {
            throw new ConcurrencyException(sprintf(
                'Batch delete failed for table "%s": expected %d deletions, got %d.',
                $this->tableName,
                count($updates),
                $affected,
            ));
        }
    }

    /** @param list<RowUpdatePlan> $updates */
    private function flushBatchInsert(array $updates): void
    {
        [$columns] = $this->insertValues($updates[0]);
        $rowPlaceholder = '(' . implode(', ', array_map($this->placeholder(...), $columns)) . ')';
        $params = [];
        foreach ($updates as $update) {
            [, $rowParams] = $this->insertValues($update);
            array_push($params, ...$rowParams);
        }
        $affected = $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) DO NOTHING',
                $this->quotedTable(),
                implode(', ', array_map($this->quoteColumn(...), $columns)),
                implode(', ', array_fill(0, count($updates), $rowPlaceholder)),
                $this->conflictTargetSql(),
            ),
            $params,
        );
        if ($affected !== count($updates)) {
            throw new ConcurrencyException(sprintf(
                'Batch insert failed for table "%s": %d rows already existed.',
                $this->tableName,
                count($updates) - $affected,
            ));
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
        $rowPlaceholder = '(' . implode(', ', array_map($this->placeholder(...), $columns)) . ')';
        $params = [];
        foreach ($updates as $update) {
            [, $rowParams] = $this->insertValues($update);
            array_push($params, ...$rowParams);
        }
        $action = 'DO NOTHING';
        if ([] !== $updates[0]->setColumns) {
            $action =
                'DO UPDATE SET '
                . implode(', ', array_map($this->conflictAssignment(...), array_keys($updates[0]->setColumns)));
        }
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) %s',
                $this->quotedTable(),
                implode(', ', array_map($this->quoteColumn(...), $columns)),
                implode(', ', array_fill(0, count($updates), $rowPlaceholder)),
                $this->conflictTargetSql(),
                $action,
            ),
            $params,
        );
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
        if (!$this->policy->allowAbsentObservations) {
            return;
        }

        $lockKeys = [];
        foreach ($checks as $check) {
            if (RowUpdatePlan::EXPECTED_ABSENT !== $check->expectedPresence) {
                continue;
            }

            $lockKeys[] = $check->key->fingerprint();
        }
        $absentChecks = count($lockKeys);
        foreach ($writes as $write) {
            if (!(RowUpdatePlan::MODE_INSERT === $write->mode || RowUpdatePlan::MODE_UPSERT === $write->mode)) {
                continue;
            }

            $lockKeys[] = $write->key->fingerprint();
        }

        if ([] === $lockKeys) {
            return;
        }
        if (!$this->connection->isTransactionActive()) {
            if (0 === $absentChecks) {
                // An autocommitted write holds no lock worth taking.
                return;
            }

            throw new \LogicException(sprintf(
                'Absent row expectations for table "%s" require an active transaction.',
                $this->tableName,
            ));
        }

        $lockKeys = array_values(array_unique($lockKeys));
        sort($lockKeys, SORT_STRING);
        // jsonb_array_elements_text() yields the keys in array order, so the locks are taken in sorted order.
        $this->connection->executeQuery(
            'SELECT count(pg_advisory_xact_lock(hashtext(?), hashtext(lock_key))) FROM jsonb_array_elements_text(?::jsonb) AS locks(lock_key)',
            [
                $this->sql->absentRowLockNamespace ?? $this->tableName . ':row',
                json_encode($lockKeys, JSON_THROW_ON_ERROR),
            ],
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
        $present = array_values(array_filter(
            $checks,
            static fn(RowUpdatePlan $check): bool => RowUpdatePlan::EXPECTED_ABSENT !== $check->expectedPresence,
        ));
        if ([] !== $present) {
            $branches = [];
            $params = [];
            foreach ($present as $check) {
                [$where, $whereParams] = $this->conditionalWhere($check);
                $branches[] = '(' . implode(' AND ', $where) . ')';
                array_push($params, ...$whereParams);
            }
            $matched = $this->connection->fetchAllAssociative(
                sprintf('SELECT 1 FROM %s WHERE %s FOR SHARE', $this->quotedTable(), implode(' OR ', $branches)),
                $params,
            );
            if (count($matched) !== count($present)) {
                throw new ConcurrencyException(sprintf(
                    'Row expectation failed for table "%s": %d of %d expected rows changed.',
                    $this->tableName,
                    count($present) - count($matched),
                    count($present),
                ));
            }
        }

        $absent = array_values(array_filter(
            $checks,
            static fn(RowUpdatePlan $check): bool => RowUpdatePlan::EXPECTED_ABSENT === $check->expectedPresence,
        ));
        if ([] !== $absent) {
            $params = [];
            foreach ($absent as $check) {
                array_push($params, ...$this->encodedKeyValues($check->key));
            }
            $existing = (int) $this->connection->fetchOne(
                sprintf(
                    'SELECT count(*) FROM %s WHERE %s',
                    $this->quotedTable(),
                    implode(' OR ', array_fill(0, count($absent), '(' . $this->keyPredicate() . ')')),
                ),
                $params,
            );
            if (0 !== $existing) {
                throw new ConcurrencyException(sprintf(
                    'Row expectation failed for table "%s": %d expected absent rows exist.',
                    $this->tableName,
                    $existing,
                ));
            }
        }
    }

    /**
     * @param list<MappedKey>|null $expected
     * @param list<MappedKey>      $ignored
     */
    private function assertExpectedRowKeys(?array $expected, array $ignored, ?MappedKey $owner = null): void
    {
        if (null === $expected) {
            return;
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException(sprintf(
                'Row-set expectations for table "%s" require an active transaction.',
                $this->tableName,
            ));
        }

        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', [
            $this->tableName,
            json_encode([$this->keyColumns(), ($owner ?? $this->configuredScope)?->values], JSON_THROW_ON_ERROR),
        ]);

        $rows = null === $owner ? $this->rows() : $this->rowsForOwner($owner, $this->keyColumns());
        $actual = array_map(static fn(MappedRow $row): string => $row->key->fingerprint(), $rows);
        $expected = array_map(static fn(MappedKey $key): string => $key->fingerprint(), $expected);
        $actual = array_values(array_diff($actual, array_map(
            static fn(MappedKey $key): string => $key->fingerprint(),
            $ignored,
        )));
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        if ($actual !== $expected) {
            throw new ConcurrencyException(sprintf(
                'Row-set expectation failed for table "%s": row keys changed.',
                $this->tableName,
            ));
        }
    }

    private function flushDelete(RowUpdatePlan $update): void
    {
        [$where, $params] = $this->conditionalWhere($update);
        $affected = $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE %s', $this->quotedTable(), implode(' AND ', $where)),
            $params,
        );

        if (1 !== $affected) {
            throw $this->concurrency('Conditional delete failed', $update);
        }
    }

    private function flushInsert(RowUpdatePlan $update): void
    {
        [$columns, $params] = $this->insertValues($update);
        $affected = $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO NOTHING',
                $this->quotedTable(),
                implode(', ', array_map($this->quoteColumn(...), $columns)),
                implode(', ', array_map($this->placeholder(...), $columns)),
                $this->conflictTargetSql(),
            ),
            $params,
        );

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
        $quotedColumns = implode(', ', array_map($this->quoteColumn(...), $columns));
        $placeholders = implode(', ', array_map($this->placeholder(...), $columns));

        $action = 'DO NOTHING';
        if ([] !== $update->setColumns) {
            $assignments = array_map($this->conflictAssignment(...), array_keys($update->setColumns));
            $action = 'DO UPDATE SET ' . implode(', ', $assignments);
        }

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) %s',
                $this->quotedTable(),
                $quotedColumns,
                $placeholders,
                $this->conflictTargetSql(),
                $action,
            ),
            $params,
        );
    }

    private function flushUpdate(RowUpdatePlan $update): void
    {
        $set = [];
        $params = [];
        foreach ($update->setColumns as $column => $value) {
            $set[] = $this->assignment($column);
            $params[] = $this->encodeColumnValue($column, $value);
        }
        [$where, $whereParams] = $this->conditionalWhere($update);
        array_push($params, ...$whereParams);

        $affected = $this->connection->executeStatement(
            sprintf('UPDATE %s SET %s WHERE %s', $this->quotedTable(), implode(', ', $set), implode(' AND ', $where)),
            $params,
        );

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
            $where[] =
                $this->sql->columns[$column]['compare']
                ?? $this->quoteColumn($column) . ' IS NOT DISTINCT FROM ' . $this->placeholder($column);
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

    private function keyPredicate(): string
    {
        return $this->sql->keyPredicate ?? implode(' AND ', $this->keyWhereClauses());
    }

    /** @return list<string> */
    private function keyWhereClauses(): array
    {
        return $this->sql->keyPredicates ?? array_map($this->assignment(...), $this->keyColumns);
    }

    /** @return list<mixed> */
    private function encodedKeyValues(MappedKey $key): array
    {
        return array_map(fn(string $column): mixed => $this->encodeColumnValue(
            $column,
            $key->value($column),
        ), $this->keyColumns());
    }

    private function scopeWhereSql(?MappedKey $scope): string
    {
        if (null === $scope) {
            return '';
        }

        return $scope->columns() === $this->sql?->ownerColumns
            ? $this->sql->ownerWhere
            : ' WHERE ' . implode(' AND ', $this->scopeWhereClauses($scope));
    }

    /** @return list<string> */
    private function scopeWhereClauses(?MappedKey $scope): array
    {
        $columns = $scope?->columns() ?? [];

        return (
            $columns === $this->sql?->ownerColumns
                ? $this->sql->ownerPredicates
                : array_map($this->assignment(...), $columns)
        );
    }

    /** @return list<mixed> */
    private function scopeParams(?MappedKey $scope): array
    {
        if (null === $scope) {
            return [];
        }

        return array_map(fn(string $column): mixed => $this->encodeColumnValue(
            $column,
            $scope->value($column),
        ), $scope->columns());
    }

    private function conflictTargetSql(): string
    {
        return $this->sql->keyList ?? implode(', ', array_map($this->quoteColumn(...), $this->keyColumns));
    }

    private function quotedTable(): string
    {
        return $this->sql->table ?? $this->connection->quoteSingleIdentifier($this->tableName);
    }

    private function quoteColumn(string $column): string
    {
        return $this->sql->columns[$column]['identifier'] ?? $this->connection->quoteSingleIdentifier($column);
    }

    private function assignment(string $column): string
    {
        return (
            $this->sql->columns[$column]['assign'] ?? $this->quoteColumn($column) . ' = ' . $this->placeholder($column)
        );
    }

    private function conflictAssignment(string $column): string
    {
        return $this->sql->columns[$column]['excluded'] ?? sprintf('%1$s = EXCLUDED.%1$s', $this->quoteColumn($column));
    }

    private function placeholder(string $column): string
    {
        $compiled = $this->sql->columns[$column]['parameter'] ?? null;
        if (null !== $compiled) {
            return $compiled;
        }

        $type = $this->columnTypes[$column] ?? null;

        return null === $type ? '?' : '?::' . $type->sqlType;
    }

    private function encodeColumnValue(string $column, mixed $value): mixed
    {
        $type = $this->columnTypes[$column] ?? null;
        if (null !== $type) {
            return $type->encode($value);
        }

        // PDO binds false as an empty string, which Postgres rejects for boolean columns.
        return match ($value) {
            true => 'true',
            false => 'false',
            default => $value,
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodeRow(array $row): array
    {
        foreach ($this->columnTypes as $column => $type) {
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $row[$column] = $type->decode($row[$column]);
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
        return new ConcurrencyException(sprintf(
            '%s for table "%s" and key %s.',
            $message,
            $this->tableName,
            $update->key,
        ));
    }
}
