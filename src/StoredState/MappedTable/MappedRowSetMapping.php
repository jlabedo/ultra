<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/** @internal Validated row-set definition with bound column codecs. */
final readonly class MappedRowSetMapping extends MappedRowSetMetadata
{
    /**
     * @param array<array-key, mixed> $columns
     * @param list<string> $keyColumns
     * @param list<string> $ownerColumns Owner columns for collections without a declared persistence dependency.
     */
    public function __construct(
        string $table,
        array $columns,
        array $keyColumns,
        array $ownerColumns = [],
        ?MappedRowSetOwner $owner = null,
    ) {
        if ('' === $table) {
            throw new \InvalidArgumentException('A mapped table name cannot be empty.');
        }

        foreach ($columns as $name => $column) {
            if (!is_string($name) || '' === $name || !$column instanceof MappedColumn) {
                throw new \InvalidArgumentException('Mapped columns must have non-empty names and representations.');
            }
        }
        /** @var array<string, MappedColumn> $columns */

        if ([] === $keyColumns || count(array_unique($keyColumns)) !== count($keyColumns)) {
            throw new \InvalidArgumentException('A mapped key must have non-empty, distinct columns.');
        }
        foreach ($keyColumns as $name) {
            $column = $columns[$name] ?? null;
            if (null === $column || $column->nullable || !in_array($column->keyType, ['int', 'string'], true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Key column "%s" must be mapped to a non-null int or string key.',
                    $name,
                ));
            }
        }
        if (null !== $owner) {
            if ([] === $owner->columns || count($owner->columns) !== count(array_unique($owner->columns))) {
                throw new \InvalidArgumentException(
                    'An owner reference must map every parent key column exactly once.',
                );
            }

            $referenceColumns = array_keys($owner->columns);
            sort($referenceColumns, SORT_STRING);
            sort($ownerColumns, SORT_STRING);
            if ([] !== $ownerColumns && $ownerColumns !== $referenceColumns) {
                throw new \InvalidArgumentException('The owner reference must use the declared owner columns.');
            }
            $ownerColumns = $referenceColumns;
        }
        if (
            [] !== array_diff($ownerColumns, $keyColumns)
            || count(array_unique($ownerColumns)) !== count($ownerColumns)
            || count($ownerColumns) >= count($keyColumns)
        ) {
            throw new \InvalidArgumentException(
                'Owner columns must be distinct and leave at least one item key column.',
            );
        }

        sort($keyColumns, SORT_STRING);
        sort($ownerColumns, SORT_STRING);
        $columnNames = array_keys($columns);
        parent::__construct(
            $table,
            $columns,
            $keyColumns,
            $ownerColumns,
            $owner,
            $columnNames,
            array_values(array_diff($columnNames, $keyColumns)),
            array_fill_keys($keyColumns, true),
            array_fill_keys($ownerColumns, true),
        );
    }
}
