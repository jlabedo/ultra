<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/** @internal SQL fragments determined entirely by a compiled row set definition. */
final readonly class PostgresMappedRowSetSql
{
    /**
     * @param array<string, array{identifier: string, parameter: string, assign: string, compare: string, excluded: string}> $columns
     * @param list<string> $keyPredicates
     * @param list<string> $ownerColumns
     * @param list<string> $ownerPredicates
     * @param list<string> $columnNames
     * @param list<string> $nonKeyColumns
     */
    public function __construct(
        public string $table,
        public array $columns,
        public array $keyPredicates,
        public string $keyPredicate,
        public string $keyList,
        public array $ownerColumns,
        public array $ownerPredicates,
        public string $ownerWhere,
        public array $columnNames,
        public array $nonKeyColumns,
        public string $rowColumns,
        public string $rowSetColumns,
        public string $absentRowLockNamespace,
    ) {}

    /** @param array<string, PostgresMappedColumnType> $types */
    public static function compile(MappedRowSetMetadata $mapping, array $types, ?self $compiled = null): self
    {
        $quote = new PostgreSQLPlatform()->quoteSingleIdentifier(...);
        $columns = $compiled->columns ?? [];
        foreach (null === $compiled ? $mapping->columnNames : array_keys($types) as $column) {
            $identifier = $columns[$column]['identifier'] ?? $quote($column);
            $type = $types[$column] ?? null;
            $parameter = null === $type ? '?' : '?::' . $type->sqlType;
            $columns[$column] = [
                'identifier' => $identifier,
                'parameter' => $parameter,
                'assign' => $identifier . ' = ' . $parameter,
                'compare' => $identifier . ' IS NOT DISTINCT FROM ' . $parameter,
                'excluded' => $identifier . ' = EXCLUDED.' . $identifier,
            ];
        }

        $predicate = static fn(string $column): string => $columns[$column]['assign'];
        if (null === $compiled || [] !== array_intersect_key($types, $mapping->keyColumnSet)) {
            $keyPredicates = array_map($predicate, $mapping->keyColumns);
            $keyPredicate = implode(' AND ', $keyPredicates);
        } else {
            $keyPredicates = $compiled->keyPredicates;
            $keyPredicate = $compiled->keyPredicate;
        }
        if (null === $compiled || [] !== array_intersect_key($types, $mapping->ownerColumnSet)) {
            $ownerPredicates = array_map($predicate, $mapping->ownerColumns);
            $ownerWhere = [] === $ownerPredicates ? '' : ' WHERE ' . implode(' AND ', $ownerPredicates);
        } else {
            $ownerPredicates = $compiled->ownerPredicates;
            $ownerWhere = $compiled->ownerWhere;
        }

        return new self(
            $compiled->table ?? $quote($mapping->table),
            $columns,
            $keyPredicates,
            $keyPredicate,
            $compiled->keyList ?? implode(', ', array_map($quote, $mapping->keyColumns)),
            $mapping->ownerColumns,
            $ownerPredicates,
            $ownerWhere,
            $mapping->columnNames,
            $mapping->nonKeyColumns,
            $compiled->rowColumns ?? implode(', ', array_map($quote, $mapping->columnNames)),
            $compiled->rowSetColumns ?? implode(', ', array_map($quote, [
                ...$mapping->keyColumns,
                ...$mapping->nonKeyColumns,
            ])),
            $compiled->absentRowLockNamespace ?? $mapping->table . ':row',
        );
    }
}
