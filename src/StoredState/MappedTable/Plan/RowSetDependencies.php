<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Plan;

use Ultra\MappedTable\MappedRowSetMetadata;
use Ultra\MappedTable\MappedRowSetOwner;

/** @internal */
final class RowSetDependencies
{
    /**
     * @param array<string, MappedRowSetMetadata> $mappings
     * @return array<string, bool> Parents before children, with a flag for each referenced row set.
     */
    public static function compile(array $mappings): array
    {
        ksort($mappings, SORT_STRING);

        $tables = [];
        $referenced = [];
        foreach ($mappings as $mapping) {
            if (isset($tables[$mapping->table])) {
                throw new \LogicException(sprintf(
                    'Table "%s" has more than one mapped row set definition.',
                    $mapping->table,
                ));
            }
            $tables[$mapping->table] = true;
            if (null !== $mapping->owner) {
                $referenced[$mapping->owner->definition] = true;
            }
        }

        $order = [];
        $visiting = [];
        foreach (array_keys($mappings) as $definition) {
            self::visit($definition, $mappings, $visiting, $order);
        }

        foreach (array_keys($order) as $definition) {
            $order[$definition] = isset($referenced[$definition]);
        }

        return $order;
    }

    /**
     * @param string $definition
     * @param array<string, MappedRowSetMetadata> $mappings
     * @param array<string, true> $visiting
     * @param array<string, true> $order
     */
    private static function visit(string $definition, array $mappings, array &$visiting, array &$order): void
    {
        if (isset($order[$definition])) {
            return;
        }
        if (isset($visiting[$definition])) {
            throw new \LogicException(sprintf('Cyclic mapped row set ownership involving "%s".', $definition));
        }

        $visiting[$definition] = true;
        $mapping = $mappings[$definition];
        $owner = $mapping->owner;
        if (null !== $owner) {
            $parent = $mappings[$owner->definition] ?? throw new \LogicException(sprintf(
                'Owner definition "%s" of table "%s" is missing.',
                $owner->definition,
                $mapping->table,
            ));
            self::visit($owner->definition, $mappings, $visiting, $order);
            self::assertOwnerMapping($mapping, $owner, $parent);
        }
        unset($visiting[$definition]);
        $order[$definition] = true;
    }

    private static function assertOwnerMapping(
        MappedRowSetMetadata $child,
        MappedRowSetOwner $owner,
        MappedRowSetMetadata $parent,
    ): void {
        $columns = array_values($owner->columns);
        sort($columns, SORT_STRING);
        if ($columns !== $parent->keyColumns) {
            throw new \LogicException(sprintf(
                'The owner reference of "%s" must map the complete key of "%s".',
                $child->table,
                $parent->table,
            ));
        }
        foreach ($owner->columns as $childColumn => $parentColumn) {
            if ($child->column($childColumn)->type !== $parent->column($parentColumn)->type) {
                throw new \LogicException(sprintf(
                    'Owner columns "%s.%s" and "%s.%s" must use the same representation.',
                    $child->table,
                    $childColumn,
                    $parent->table,
                    $parentColumn,
                ));
            }
        }
    }
}
