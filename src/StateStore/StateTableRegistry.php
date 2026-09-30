<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetAdapterFactory;
use Ultra\UnitOfWork\Exception\ConcurrencyException;
use Ultra\UnitOfWork\UnitOfWork;

final class StateTableRegistry implements UnitOfWork
{
    /** @var array<string, MappedRowSet> */
    private array $tables;

    /** @var array<string, MappedRowSet> */
    private array $collectionTables = [];

    /** @var list<array{query: \Closure, expected: mixed, name: ?string}> */
    private array $queryExpectations = [];

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

    public function collectionFor(StateCollectionMetadata $collection, mixed $normalizedOwnerId): MappedRowSet
    {
        if (null === $this->adapterFactory) {
            throw new \LogicException(sprintf(
                'No mapped row set adapter factory was registered for state collection "%s".',
                $collection->property,
            ));
        }

        $scope = $collection->ownerKey($normalizedOwnerId);
        $key = $collection->tableKey.'#'.$scope->fingerprint();

        return $this->collectionTables[$key] ??= new MappedRowSet($this->adapterFactory->createScoped(
            $collection->tableName,
            $collection->keyColumns(),
            $scope,
        ));
    }

    public function flush(): void
    {
        MappedRowSet::flushAll(array_values([...$this->tables, ...$this->collectionTables]), function (): void {
            foreach ($this->queryExpectations as $expectation) {
                if (($expectation['query'])() !== $expectation['expected']) {
                    throw new ConcurrencyException(sprintf('Query expectation failed%s.', null === $expectation['name'] ? '' : ' for "'.$expectation['name'].'"'));
                }
            }
        });
    }

    public function hasPendingChanges(): bool
    {
        if ([] !== $this->queryExpectations) {
            return true;
        }

        foreach ([...$this->tables, ...$this->collectionTables] as $table) {
            if ($table->hasPendingChanges()) {
                return true;
            }
        }

        return false;
    }

    public function clear(): void
    {
        foreach ([...$this->tables, ...$this->collectionTables] as $table) {
            $table->clear();
        }

        $this->collectionTables = [];
        $this->queryExpectations = [];
    }

    /** @param callable(): mixed $query */
    public function expectQuery(callable $query, mixed $expected, ?string $name = null): void
    {
        self::assertQueryValue($expected);
        $this->queryExpectations[] = ['query' => $query(...), 'expected' => $expected, 'name' => $name];
    }

    private static function assertQueryValue(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertQueryValue($item);
            }
        } elseif (null !== $value && !is_scalar($value)) {
            throw new \InvalidArgumentException('Query expectations require scalars, null or arrays of these values.');
        }
    }
}
