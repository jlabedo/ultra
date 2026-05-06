<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\MappedTable\MappedRowSet;

final readonly class StateTableRegistry
{
    /** @var array<string, MappedRowSet> */
    private array $tables;

    /**
     * @param array<string, MappedRowSet> $tables
     */
    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    public function tableFor(StateMetadata $metadata): MappedRowSet
    {
        $table = $this->tables[$metadata->tableKey] ?? null;
        if (!$table instanceof MappedRowSet) {
            throw new \LogicException(sprintf(
                'No mapped row set service was registered for state "%s".',
                $metadata->stateClass,
            ));
        }

        return $table;
    }
}
