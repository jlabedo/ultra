<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\UnitOfWork\Exception\ConcurrencyException;

final readonly class InMemoryMappedRowSetAdapter implements MappedRowSetAdapter
{
    /**
     * @param list<string> $configuredKeyColumns
     */
    public function __construct(
        private InMemoryRowDatabase $database,
        private string $tableName,
        private array $configuredKeyColumns = ['id'],
        private ?MappedKey $configuredScope = null,
    ) {
        self::assertSchema($configuredKeyColumns, $configuredScope);
    }

    public function keyColumns(): array
    {
        $columns = $this->configuredKeyColumns;
        sort($columns, SORT_STRING);

        return $columns;
    }

    public function scope(): ?MappedKey
    {
        return $this->configuredScope;
    }

    public function row(MappedKey $key, array $columns = []): array|false
    {
        $this->assertKey($key);

        return $this->database->rowByKey($this->tableName, $key, $columns);
    }

    public function rows(array $columns = []): array
    {
        return $this->database->rowsByKey($this->tableName, $this->keyColumns(), $columns, $this->configuredScope);
    }

    public function apply(RowSetUpdatePlan $plan): void
    {
        foreach ($plan->rowUpdates as $update) {
            $this->assertKey($update->key);
        }

        $this->assertExpectedRowKeys($plan->expectedRowKeys, $plan->ignoredRowKeys);

        foreach ($plan->rowUpdates as $update) {
            if ($update->writes()) {
                continue;
            }

            $this->checkRow($update);
        }

        foreach ($plan->rowUpdates as $update) {
            if (!$update->writes()) {
                continue;
            }

            match ($update->mode) {
                RowUpdatePlan::MODE_DELETE => $this->applyDelete($update),
                RowUpdatePlan::MODE_INSERT => $this->applyInsert($update),
                RowUpdatePlan::MODE_REPLACE => $this->applyReplace($update),
                RowUpdatePlan::MODE_UPSERT => $this->applyUpsert($update),
                RowUpdatePlan::MODE_UPDATE => $this->applyUpdate($update),
                default => throw new \LogicException(sprintf('Unsupported row update mode "%s".', $update->mode)),
            };
        }

        if (null !== $plan->retainedRowKeys) {
            $retained = [];
            foreach ($plan->retainedRowKeys as $key) {
                $this->assertKey($key);
                $retained[$key->fingerprint()] = true;
            }
            foreach ($this->rows() as $row) {
                if (isset($retained[$row->key->fingerprint()])) {
                    continue;
                }

                $this->database->deleteRowByKey($this->tableName, $row->key);
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

        $actual = array_map(static fn(MappedRow $row): string => $row->key->fingerprint(), $this->rows());
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

    private function checkRow(RowUpdatePlan $update): void
    {
        if (RowUpdatePlan::EXPECTED_ABSENT === $update->expectedPresence) {
            if (false !== $this->database->rowByKey($this->tableName, $update->key)) {
                throw $this->concurrency('Expected absent row exists', $update);
            }

            return;
        }

        $this->requireMatchingRow($update, 'Row expectation failed');
    }

    private function applyDelete(RowUpdatePlan $update): void
    {
        $this->requireMatchingRow($update, 'Conditional delete failed');
        $this->database->deleteRowByKey($this->tableName, $update->key);
    }

    private function applyInsert(RowUpdatePlan $update): void
    {
        if (false !== $this->database->rowByKey($this->tableName, $update->key)) {
            throw $this->concurrency('Insert failed because the row already exists', $update);
        }

        $this->database->writeRowByKey($this->tableName, $update->key, $update->setColumns);
    }

    private function applyReplace(RowUpdatePlan $update): void
    {
        $this->requireMatchingRow($update, 'Conditional replace failed');
        $this->database->writeRowByKey($this->tableName, $update->key, $update->setColumns);
    }

    private function applyUpsert(RowUpdatePlan $update): void
    {
        $row = $this->database->rowByKey($this->tableName, $update->key);
        $row = false === $row ? [] : $row;
        $this->database->writeRowByKey($this->tableName, $update->key, [...$row, ...$update->setColumns]);
    }

    private function applyUpdate(RowUpdatePlan $update): void
    {
        $row = $this->requireMatchingRow($update, 'Conditional update failed');
        $this->database->writeRowByKey($this->tableName, $update->key, [...$row, ...$update->setColumns]);
    }

    /** @return array<string, mixed> */
    private function requireMatchingRow(RowUpdatePlan $update, string $message): array
    {
        $row = $this->database->rowByKey($this->tableName, $update->key);
        if (false === $row) {
            throw $this->concurrency($message, $update);
        }

        foreach ($update->expectedColumns as $column => $value) {
            if (!array_key_exists($column, $row) || $row[$column] !== $value) {
                throw $this->concurrency($message, $update);
            }
        }

        return $row;
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

    private function assertKey(MappedKey $key): void
    {
        $key->assertColumns($this->keyColumns());
        if (null !== $this->configuredScope && !$key->matches($this->configuredScope)) {
            throw new \InvalidArgumentException('Mapped key does not belong to adapter scope.');
        }
    }

    /** @param list<string> $keyColumns */
    private static function assertSchema(array $keyColumns, ?MappedKey $scope): void
    {
        if ([] === $keyColumns || count($keyColumns) !== count(array_unique($keyColumns))) {
            throw new \InvalidArgumentException('Mapped row-set key columns must be non-empty and unique.');
        }
        foreach ($keyColumns as $column) {
            if ('' === $column) {
                throw new \InvalidArgumentException('Mapped row-set key column names must be non-empty.');
            }
        }

        if (null !== $scope && [] !== array_diff($scope->columns(), $keyColumns)) {
            throw new \InvalidArgumentException('Mapped row-set scope columns must belong to its key.');
        }
    }
}
