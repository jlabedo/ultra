<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
final readonly class InMemoryRowSetStorage implements MappedRowSetStorage
{
    /** @var list<string> */
    private array $keyColumns;

    /**
     * @param list<string>|MappedRowSetMetadata $configuredKeyColumns
     */
    public function __construct(
        private InMemoryRowDatabase $database,
        private string $tableName,
        array|MappedRowSetMetadata $configuredKeyColumns = ['id'],
        private MappedTablePolicy $policy = new MappedTablePolicy(),
    ) {
        if ($configuredKeyColumns instanceof MappedRowSetMetadata) {
            $this->keyColumns = $configuredKeyColumns->keyColumns;
        } else {
            self::assertSchema($configuredKeyColumns);
            sort($configuredKeyColumns, SORT_STRING);
            $this->keyColumns = $configuredKeyColumns;
        }
    }

    public function keyColumns(): array
    {
        return $this->keyColumns;
    }

    public function row(MappedKey $key, array $columns = []): array|false
    {
        $this->assertKey($key);

        return $this->database->rowByKey($this->tableName, $key, $columns);
    }

    public function rows(array $columns = [], ?MappedKey $owner = null): array
    {
        return $this->database->rowsByKey($this->tableName, $this->keyColumns(), $columns, $owner);
    }

    public function check(RowSetUpdatePlan $plan): void
    {
        $this->policy->assertSupported($plan, $this->tableName);

        foreach ($plan->rowUpdates as $update) {
            $this->assertKey($update->key);
        }

        foreach ($plan->memberships as $membership) {
            $this->assertExpectedRowKeys($membership);
        }

        foreach ($plan->rowUpdates as $update) {
            if ($update->writes()) {
                continue;
            }

            $this->checkRow($update);
        }
    }

    public function writeRows(array $writes): void
    {
        foreach ($writes as $update) {
            match ($update->mode) {
                RowUpdatePlan::MODE_DELETE => $this->applyDelete($update),
                RowUpdatePlan::MODE_INSERT => $this->applyInsert($update),
                RowUpdatePlan::MODE_REPLACE => $this->applyReplace($update),
                RowUpdatePlan::MODE_UPSERT => $this->applyUpsert($update),
                RowUpdatePlan::MODE_UPDATE => $this->applyUpdate($update),
                default => throw new \LogicException(sprintf('Unsupported row update mode "%s".', $update->mode)),
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

        $retained = [];
        foreach ($keys as $key) {
            $this->assertKey($key);
            $retained[$key->fingerprint()] = true;
        }
        $rows = $this->rows($this->keyColumns(), $owner);
        foreach ($rows as $row) {
            if (isset($retained[$row->key->fingerprint()])) {
                continue;
            }

            $this->database->deleteRowByKey($this->tableName, $row->key);
        }
    }

    private function assertExpectedRowKeys(RowMembershipPlan $membership): void
    {
        if (null === $membership->expectedRowKeys) {
            return;
        }

        $rows = $this->rows($this->keyColumns(), $membership->owner);
        if (!$membership->matchesExpectedRows($rows)) {
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
            if (($row[$column] ?? null) !== $value) {
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
    }

    /** @param list<string> $keyColumns */
    private static function assertSchema(array $keyColumns): void
    {
        if ([] === $keyColumns || count($keyColumns) !== count(array_unique($keyColumns))) {
            throw new \InvalidArgumentException('Mapped row-set key columns must be non-empty and unique.');
        }
        foreach ($keyColumns as $column) {
            if ('' === $column) {
                throw new \InvalidArgumentException('Mapped row-set key column names must be non-empty.');
            }
        }
    }
}
