<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Storage;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ultra\MappedTable\MappedRowSetMetadata;
use Ultra\MappedTable\PostgresMappedColumnType;

/** @internal PostgreSQL codecs and SQL fragments compiled from a row set definition. */
final readonly class PostgresRowSetSql
{
    /**
     * @param array<string, array{identifier: string, parameter: string, assign: string, compare: string, excluded: string}> $columns
     * @param list<string> $keyPredicates
     * @param list<string> $ownerColumns
     * @param list<string> $ownerPredicates
     * @param list<string> $columnNames
     * @param list<string> $nonKeyColumns
     * @param array<string, PostgresMappedColumnType> $columnTypes
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
        public array $columnTypes,
    ) {}

    /** @param array<string, PostgresMappedColumnType> $types */
    public static function compile(MappedRowSetMetadata $mapping, array $types): self
    {
        self::validateColumnTypes($mapping, $types);
        $quote = new PostgreSQLPlatform()->quoteSingleIdentifier(...);
        $columns = [];
        foreach ($mapping->columnNames as $column) {
            $identifier = $quote($column);
            $type = $types[$column] ?? null;
            $parameter = null === $type ? '?' : '?::' . $type->value;
            $columns[$column] = [
                'identifier' => $identifier,
                'parameter' => $parameter,
                'assign' => $identifier . ' = ' . $parameter,
                'compare' => $identifier . ' IS NOT DISTINCT FROM ' . $parameter,
                'excluded' => $identifier . ' = EXCLUDED.' . $identifier,
            ];
        }

        $predicate = static fn(string $column): string => $columns[$column]['assign'];
        $keyPredicates = array_map($predicate, $mapping->keyColumns);
        $ownerPredicates = array_map($predicate, $mapping->ownerColumns);
        $identifier = static fn(string $column): string => $columns[$column]['identifier'];
        $keyList = implode(', ', array_map($identifier, $mapping->keyColumns));
        $rowSetColumns = [] === $mapping->nonKeyColumns
            ? $keyList
            : $keyList . ', ' . implode(', ', array_map($identifier, $mapping->nonKeyColumns));

        return new self(
            $quote($mapping->table),
            $columns,
            $keyPredicates,
            implode(' AND ', $keyPredicates),
            $keyList,
            $mapping->ownerColumns,
            $ownerPredicates,
            [] === $ownerPredicates ? '' : ' WHERE ' . implode(' AND ', $ownerPredicates),
            $mapping->columnNames,
            $mapping->nonKeyColumns,
            implode(', ', array_map($identifier, $mapping->columnNames)),
            $rowSetColumns,
            $mapping->table . ':row',
            $types,
        );
    }

    /** @param array<array-key, mixed> $types */
    public static function validateColumnTypes(MappedRowSetMetadata $mapping, array $types): void
    {
        foreach ($types as $column => $type) {
            if (!is_string($column) || !$type instanceof PostgresMappedColumnType) {
                throw new \InvalidArgumentException(sprintf(
                    'PostgreSQL codecs for table "%s" must map column names to PostgresMappedColumnType cases.',
                    $mapping->table,
                ));
            }
            $type->assertSupports($column, $mapping->column($column));
        }
    }
}
