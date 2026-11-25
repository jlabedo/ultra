<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests\Support;

use Ultra\MappedTable\GuardLockMode;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetMetadata;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\Storage\InMemoryRowSetStorage;

final class RecordingGuardMappedTableAdapter extends MappedTableAdapter
{
    /** @param \Closure(string, string, GuardLockMode): void $record */
    public function __construct(
        private readonly InMemoryRowDatabase $database,
        private readonly \Closure $record,
    ) {}

    public function createDefined(MappedRowSetMetadata $mapping): MappedRowSet
    {
        return $this->bind($mapping, new InMemoryRowSetStorage($this->database, $mapping));
    }

    public function assertActiveTransaction(): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new \LogicException('Expected an active test transaction.');
        }
    }

    public function acquireGuardLock(string $namespace, string $key, GuardLockMode $mode): void
    {
        ($this->record)($namespace, $key, $mode);
    }
}
