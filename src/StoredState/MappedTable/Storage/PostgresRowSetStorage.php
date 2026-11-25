<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Storage;

use Doctrine\DBAL\Connection;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRow;
use Ultra\MappedTable\MappedTablePolicy;
use Ultra\MappedTable\Plan\RowMembershipPlan;
use Ultra\MappedTable\Plan\RowSetUpdatePlan;
use Ultra\MappedTable\Plan\RowUpdatePlan;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
final readonly class PostgresRowSetStorage implements RowSetStorage
{
    /** @param list<string> $keyColumns Validated and ordered by the mapping. */
    public function __construct(
        private Connection $connection,
        private string $tableName,
        private array $keyColumns,
        private PostgresRowSetSql $sql,
        private MappedTablePolicy $policy = new MappedTablePolicy(),
    ) {}

    public function keyColumns(): array
    {
        return $this->keyColumns;
    }

    public function row(MappedKey $key, array $columns): array|false
    {
        $this->assertKey($key);
        $projection = match (true) {
            $columns === $this->sql->columnNames => $this->sql->rowColumns,
            default => implode(', ', array_map($this->quoteColumn(...), array_values(array_unique($columns)))),
        };

        $row = $this->connection->fetchAssociative(
            sprintf('SELECT %s FROM %s WHERE %s', $projection, $this->sql->table, $this->sql->keyPredicate),
            $this->encodedKeyValues($key),
        );

        return false === $row ? false : $this->decodeRow($row);
    }

    public function rows(array $columns, ?MappedKey $owner): array
    {
        $projection = match (true) {
            $columns === $this->keyColumns => $this->sql->keyList,
            $columns === $this->sql->nonKeyColumns => $this->sql->rowSetColumns,
            default => implode(', ', array_map(
                $this->quoteColumn(...),
                array_values(array_unique([...$this->keyColumns, ...$columns])),
            )),
        };

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM %s%s ORDER BY %s',
                $projection,
                $this->sql->table,
                null === $owner ? '' : $this->sql->ownerWhere,
                $this->sql->keyList,
            ),
            $this->ownerParams($owner),
        );

        $mapped = [];
        foreach ($rows as $row) {
            $row = $this->decodeRow($row);
            $key = $this->keyFromRow($row);
            $mapped[] = new MappedRow($key, array_diff_key($row, $key->values));
        }

        return $mapped;
    }

    public function rowsByKeys(array $keys, array $columns): array
    {
        $columns = array_values(array_unique($columns));
        $projection = implode(', ', array_map($this->quoteColumn(...), $columns));
        // Associate each result with the requested key independently of the result order.
        $querySuffix = sprintf(', %s FROM %s WHERE %s', $projection, $this->sql->table, $this->sql->keyPredicate);
        $fingerprints = array_keys($keys);
        $branches = [];
        $params = [];
        foreach ($fingerprints as $index => $fingerprint) {
            $branches[] = 'SELECT ' . $index . $querySuffix;
            array_push($params, ...$this->encodedKeyValues($keys[$fingerprint]));
        }

        $mapped = [];
        foreach ($this->connection->fetchAllNumeric(implode(' UNION ALL ', $branches), $params) as $row) {
            $index = (int) array_shift($row);
            $mapped[$fingerprints[$index]] = $this->decodeRow(array_combine($columns, $row));
        }

        return $mapped;
    }

    /** @param array<string, mixed> $row */
    private function keyFromRow(array $row): MappedKey
    {
        $key = [];
        foreach ($this->keyColumns as $column) {
            $value = $row[$column] ?? null;
            if (!is_int($value) && !is_string($value)) {
                throw new \LogicException(sprintf(
                    'Table "%s" returned no scalar key column "%s".',
                    $this->tableName,
                    $column,
                ));
            }
            $codec = $this->sql->columnTypes[$column] ?? null;
            $key[$column] = $codec?->normalize($value, 6, preserveTimestampOffset: false) ?? $value;
        }

        return new MappedKey($key);
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
        foreach ($plan->memberships as $membership) {
            $this->assertExpectedRowKeys($membership);
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
        foreach ($plan->memberships as $membership) {
            $this->deleteRowsOutside($membership->retainedRowKeys, $membership->owner);
        }
    }

    /** @param list<MappedKey>|null $keys */
    private function deleteRowsOutside(?array $keys, ?MappedKey $owner): void
    {
        if (null === $keys) {
            return;
        }

        $where = null === $owner ? [] : $this->sql->ownerPredicates;
        $params = $this->ownerParams($owner);
        $retained = [];
        foreach ($keys as $key) {
            $this->assertKey($key);
            $retained[] = '(' . $this->sql->keyPredicate . ')';
            array_push($params, ...$this->encodedKeyValues($key));
        }
        if ([] !== $retained) {
            $where[] = 'NOT (' . implode(' OR ', $retained) . ')';
        }

        $this->connection->executeStatement(
            sprintf('DELETE FROM %s%s', $this->sql->table, [] === $where ? '' : ' WHERE ' . implode(' AND ', $where)),
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
            $branches[] = '(' . $this->sql->keyPredicate . ')';
            array_push($params, ...$this->encodedKeyValues($update->key));
        }
        $affected = $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE %s', $this->sql->table, implode(' OR ', $branches)),
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
                $this->sql->table,
                implode(', ', array_map($this->quoteColumn(...), $columns)),
                implode(', ', array_fill(0, count($updates), $rowPlaceholder)),
                $this->sql->keyList,
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
                $this->sql->table,
                implode(', ', array_map($this->quoteColumn(...), $columns)),
                implode(', ', array_fill(0, count($updates), $rowPlaceholder)),
                $this->sql->keyList,
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
        foreach ($writes as $write) {
            if (!(RowUpdatePlan::MODE_INSERT === $write->mode || RowUpdatePlan::MODE_UPSERT === $write->mode)) {
                continue;
            }

            $lockKeys[] = $write->key->fingerprint();
        }

        if ([] === $lockKeys) {
            return;
        }
        $lockKeys = array_values(array_unique($lockKeys));
        sort($lockKeys, SORT_STRING);
        // jsonb_array_elements_text() yields the keys in array order, so the locks are taken in sorted order.
        $this->connection->executeQuery(
            'SELECT count(pg_advisory_xact_lock(hashtext(?), hashtext(lock_key))) FROM jsonb_array_elements_text(?::jsonb) AS locks(lock_key)',
            [
                $this->sql->absentRowLockNamespace,
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
                sprintf('SELECT 1 FROM %s WHERE %s FOR SHARE', $this->sql->table, implode(' OR ', $branches)),
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
                    $this->sql->table,
                    implode(' OR ', array_fill(0, count($absent), '(' . $this->sql->keyPredicate . ')')),
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

    private function assertExpectedRowKeys(RowMembershipPlan $membership): void
    {
        if (null === $membership->expectedRowKeys) {
            return;
        }
        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', [
            $this->tableName,
            json_encode([$this->keyColumns(), $membership->owner?->values], JSON_THROW_ON_ERROR),
        ]);

        $rows = $this->rows($this->keyColumns(), $membership->owner);
        if (!$membership->matchesExpectedRows($rows)) {
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
            sprintf('DELETE FROM %s WHERE %s', $this->sql->table, implode(' AND ', $where)),
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
                $this->sql->table,
                implode(', ', array_map($this->quoteColumn(...), $columns)),
                implode(', ', array_map($this->placeholder(...), $columns)),
                $this->sql->keyList,
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
                $this->sql->table,
                $quotedColumns,
                $placeholders,
                $this->sql->keyList,
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
            sprintf('UPDATE %s SET %s WHERE %s', $this->sql->table, implode(', ', $set), implode(' AND ', $where)),
            $params,
        );

        if (1 !== $affected) {
            throw $this->concurrency('Conditional update failed', $update);
        }
    }

    /** @return array{list<string>, list<mixed>} */
    private function conditionalWhere(RowUpdatePlan $update): array
    {
        $where = $this->sql->keyPredicates;
        $params = $this->encodedKeyValues($update->key);
        foreach ($update->expectedColumns as $column => $value) {
            $where[] = $this->sql->columns[$column]['compare'];
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

    /** @return list<mixed> */
    private function encodedKeyValues(MappedKey $key): array
    {
        return array_map(fn(string $column): mixed => $this->encodeColumnValue(
            $column,
            $key->value($column),
        ), $this->keyColumns());
    }

    /** @return list<mixed> */
    private function ownerParams(?MappedKey $owner): array
    {
        if (null === $owner) {
            return [];
        }

        return array_map(fn(string $column): mixed => $this->encodeColumnValue(
            $column,
            $owner->value($column),
        ), $this->sql->ownerColumns);
    }

    private function quoteColumn(string $column): string
    {
        return $this->sql->columns[$column]['identifier'];
    }

    private function assignment(string $column): string
    {
        return $this->sql->columns[$column]['assign'];
    }

    private function conflictAssignment(string $column): string
    {
        return $this->sql->columns[$column]['excluded'];
    }

    private function placeholder(string $column): string
    {
        return $this->sql->columns[$column]['parameter'];
    }

    private function encodeColumnValue(string $column, mixed $value): mixed
    {
        $type = $this->sql->columnTypes[$column] ?? null;
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
        foreach ($this->sql->columnTypes as $column => $type) {
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
