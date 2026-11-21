<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/** @internal Compiled dependency indexes for decision reads and writer plans. */
final readonly class QueryGuardRules
{
    /**
     * @param list<QueryGuardRule> $rules
     * @param array<array-key, array<class-string<MappedRowSetDefinition>, list<int>>> $byName
     * @param array<class-string<MappedRowSetDefinition>, list<int>> $byRowSet
     * @param array<array-key, string> $namespaces
     */
    public function __construct(
        public array $rules,
        public array $byName,
        public array $byRowSet,
        public array $namespaces,
    ) {}

    /**
     * @param list<QueryGuardDefinition> $definitions
     * @param array<string, MappedRowSetMetadata> $mappings
     */
    public static function compile(array $definitions, array $mappings): self
    {
        $rules = [];
        $byName = [];
        $byRowSet = [];
        $namespaces = [];

        foreach ($definitions as $definition) {
            $name = $definition->name;
            if ('' === trim($name) || [] === $definition->dependencies || isset($namespaces[$name])) {
                throw new \InvalidArgumentException(
                    'A query guard needs a unique, non-empty name and at least one dependency.',
                );
            }

            $namespaces[$name] = 'ultra:query_guard:' . $name;
            $indexes = [];
            foreach ($definition->dependencies as $dependency) {
                $mapping = $mappings[$dependency->rowSet] ?? throw new \InvalidArgumentException(sprintf(
                    'Query guard "%s" watches unknown row set definition "%s".',
                    $name,
                    $dependency->rowSet,
                ));
                $valueColumn = $mapping->column($dependency->valueColumn);
                if ($valueColumn->nullable || !in_array($valueColumn->type, ['int', 'string'], true)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Query guard "%s" needs a non-null int or string value column, got "%s.%s".',
                        $name,
                        $mapping->table,
                        $dependency->valueColumn,
                    ));
                }

                $watched = null;
                if (null !== $dependency->columns) {
                    $watched = [$dependency->valueColumn => true];
                    foreach ($dependency->columns as $column) {
                        $mapping->column($column);
                        $watched[$column] = true;
                    }
                }

                $index = $indexes[$dependency->rowSet][$dependency->valueColumn] ?? null;
                if (null === $index) {
                    $index = count($rules);
                    $indexes[$dependency->rowSet][$dependency->valueColumn] = $index;
                    $byName[$name][$dependency->rowSet][] = $index;
                    $byRowSet[$dependency->rowSet][] = $index;
                } else {
                    $previous = $rules[$index]->watchedColumns;
                    $watched = null === $previous || null === $watched ? null : $previous + $watched;
                }
                $rules[$index] = new QueryGuardRule(
                    $name,
                    $dependency->valueColumn,
                    isset($mapping->keyColumnSet[$dependency->valueColumn]),
                    isset($mapping->ownerColumnSet[$dependency->valueColumn]),
                    $watched,
                );
            }
        }

        ksort($namespaces, SORT_STRING);

        return new self(array_values($rules), $byName, $byRowSet, $namespaces);
    }
}
