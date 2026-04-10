<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\MappedTable\MappedTable;

final readonly class StateTableRegistry
{
    /** @var array<string, MappedTable> */
    private array $tables;

    /**
     * @param array<string, MappedTable> $tables
     */
    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    public function tableFor(StateMetadata $metadata): MappedTable
    {
        $table = $this->tables[$metadata->tableKey] ?? null;
        if (! $table instanceof MappedTable) {
            throw new \LogicException(sprintf(
                'No mapped table service was registered for state "%s".',
                $metadata->stateClass,
            ));
        }

        return $table;
    }
}
