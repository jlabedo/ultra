<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Storage;

use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRow;
use Ultra\MappedTable\MappedRowSetMetadata;
use Ultra\MappedTable\MappedTablePolicy;
use Ultra\MappedTable\Plan\RowMembershipPlan;
use Ultra\MappedTable\Plan\RowSetUpdatePlan;
use Ultra\MappedTable\Plan\RowUpdatePlan;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
final readonly class InMemoryRowSetStorage implements RowSetStorage
{
    public function __construct(
        private InMemoryRowDatabase $database,
        private MappedRowSetMetadata $mapping,
        private MappedTablePolicy $policy = new MappedTablePolicy(),
    ) {}

    public function keyColumns(): array
    {
        return $this->mapping->keyColumns;
    }

    public function row(MappedKey $key, array $columns): array|false
    {
        $this->assertKey($key);

        $row = $this->database->rowByKey($this->mapping->table, $key, $columns);

        return false === $row ? false : $this->mapping->copyValues($row);
    }

    public function rows(array $columns, ?MappedKey $owner): array
    {
        $rows = $this->database->rowsByKey($this->mapping->table, $this->keyColumns(), $columns, $owner);

        return array_map(
            fn(MappedRow $row): MappedRow => new MappedRow($row->key, $this->mapping->copyValues($row->columns)),
            $rows,
        );
    }

    public function rowsByKeys(array $keys, array $columns): array
    {
        $rows = $this->database->rowsForKeys($this->mapping->table, $keys, $columns);

        return array_map($this->mapping->copyValues(...), $rows);
    }

    public function check(RowSetUpdatePlan $plan): void
    {
        $this->policy->assertSupported($plan, $this->mapping->table);

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

            $this->database->deleteRowByKey($this->mapping->table, $row->key);
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
                $this->mapping->table,
            ));
        }
    }

    private function checkRow(RowUpdatePlan $update): void
    {
        if (RowUpdatePlan::EXPECTED_ABSENT === $update->expectedPresence) {
            if (false !== $this->database->rowByKey($this->mapping->table, $update->key)) {
                throw $this->concurrency('Expected absent row exists', $update);
            }

            return;
        }

        $this->requireMatchingRow($update, 'Row expectation failed');
    }

    private function applyDelete(RowUpdatePlan $update): void
    {
        $this->requireMatchingRow($update, 'Conditional delete failed');
        $this->database->deleteRowByKey($this->mapping->table, $update->key);
    }

    private function applyInsert(RowUpdatePlan $update): void
    {
        if (false !== $this->database->rowByKey($this->mapping->table, $update->key)) {
            throw $this->concurrency('Insert failed because the row already exists', $update);
        }

        $this->writeValues($update, []);
    }

    private function applyReplace(RowUpdatePlan $update): void
    {
        $this->requireMatchingRow($update, 'Conditional replace failed');
        $this->writeValues($update, []);
    }

    private function applyUpsert(RowUpdatePlan $update): void
    {
        $row = $this->database->rowByKey($this->mapping->table, $update->key);
        if (false === $row) {
            $this->writeValues($update, []);

            return;
        }

        // The insert candidate must also fit the declared integer range on conflict.
        foreach ($update->increments as $column => $delta) {
            $this->mapping->column($column)->increment(0, $delta);
        }
        $this->writeValues($update, $row, startsAtZero: false);
    }

    private function applyUpdate(RowUpdatePlan $update): void
    {
        $row = $this->requireMatchingRow($update, 'Conditional update failed');
        $this->writeValues($update, $row, startsAtZero: false);
    }

    /** @param array<string, mixed> $row */
    private function writeValues(RowUpdatePlan $update, array $row, bool $startsAtZero = true): void
    {
        foreach ($update->increments as $column => $delta) {
            $row[$column] = $this->mapping->column($column)->increment(
                $startsAtZero ? 0 : $row[$column] ?? null,
                $delta,
            );
        }
        $this->database->writeRowByKey($this->mapping->table, $update->key, [...$row, ...$update->setColumns]);
    }

    /** @return array<string, mixed> */
    private function requireMatchingRow(RowUpdatePlan $update, string $message): array
    {
        $row = $this->database->rowByKey($this->mapping->table, $update->key);
        if (false === $row) {
            throw $this->concurrency($message, $update);
        }

        foreach ($update->expectedColumns as $column => $value) {
            if (!$this->mapping->column($column)->equivalent($row[$column] ?? null, $value)) {
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
            $this->mapping->table,
            $update->key,
        ));
    }

    private function assertKey(MappedKey $key): void
    {
        $key->assertColumns($this->keyColumns());
    }
}
