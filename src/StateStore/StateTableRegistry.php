<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetAdapterFactory;
use Ultra\UnitOfWork\UnitOfWork;

final class StateTableRegistry implements UnitOfWork
{
    /** @var array<string, MappedRowSet> */
    private array $tables;

    /** @var array<string, MappedRowSet> */
    private array $collectionTables = [];

    /**
     * @param array<string, MappedRowSet> $tables
     */
    public function __construct(
        array $tables,
        private ?MappedRowSetAdapterFactory $adapterFactory = null,
    ) {
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

    public function collectionFor(StateCollectionMetadata $collection, int|string $ownerId): MappedRowSet
    {
        if (null === $this->adapterFactory) {
            throw new \LogicException(sprintf(
                'No mapped row set adapter factory was registered for state collection "%s".',
                $collection->property,
            ));
        }

        $key = implode('#', [$collection->tableName, $collection->idColumn, $collection->ownerColumn, (string) $ownerId]);

        return $this->collectionTables[$key] ??= new MappedRowSet($this->adapterFactory->createScoped(
            $collection->tableName,
            $collection->idColumn,
            [$collection->ownerColumn => $ownerId],
        ));
    }

    public function flush(): void
    {
        foreach ([...$this->tables, ...$this->collectionTables] as $table) {
            $table->flush();
        }
    }

    public function clear(): void
    {
        foreach ([...$this->tables, ...$this->collectionTables] as $table) {
            $table->clear();
        }

        $this->collectionTables = [];
    }
}
