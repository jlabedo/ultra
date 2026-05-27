<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\UnitOfWork\UnitOfWork;

/**
 * Adapter-backed unit of work for the "owned child set" pattern.
 *
 * A parent key (one or more columns) owns a variable-length set of detail rows
 * whose only meaningful identity is the parent key. The only operation supported
 * against a parent key is "replace the whole owned set."
 *
 * This is a sibling of MappedRowSet — not a replacement. MappedRowSet's invariant
 * is "row identity = primary key"; this primitive's invariant is "set identity =
 * parent key, and the rows under it are bulk-replaceable."
 */
final class MappedOwnedRowSet implements UnitOfWork
{
    /** @var array<string, OwnedSetReplace> */
    private array $replacements = [];

    /** @var array<string, OwnedSetParentMatchDelete> */
    private array $parentMatchDeletes = [];

    public function __construct(
        private readonly MappedOwnedRowSetAdapter $adapter,
    ) {}

    /**
     * @param array<string, int|string> $parentKey
     */
    public function withParent(array $parentKey): PendingOwnedSetReplace
    {
        $normalized = $this->normalizeParentKey($parentKey);
        $bufferKey = self::bufferKey($normalized);

        return new PendingOwnedSetReplace(function (array $rows) use ($normalized, $bufferKey): void {
            $this->replacements[$bufferKey] = new OwnedSetReplace($normalized, array_values($rows));
        });
    }

    /**
     * Delete every owned set whose parent key matches the supplied partial key.
     *
     * The partial key must be a non-empty subset of the adapter's parent columns.
     * Any pending replacements whose parent matches the predicate are dropped
     * from the buffer — so a delete queued after a replace wins, and a replace
     * queued after a delete (whether for the same or a different parent) wins.
     * At flush time the adapter applies all queued partial-key deletes first,
     * then all replacements.
     *
     * @param array<string, int|string> $partialParentKey
     */
    public function deleteParentsMatching(array $partialParentKey): void
    {
        $normalized = $this->normalizePartialParentKey($partialParentKey);

        // Drop matching buffered replaces; the delete supersedes them.
        foreach ($this->replacements as $bufferKey => $replace) {
            if (self::parentMatchesPartial($replace->parentKey, $normalized)) {
                unset($this->replacements[$bufferKey]);
            }
        }

        $this->parentMatchDeletes[self::bufferKey($normalized)] = new OwnedSetParentMatchDelete($normalized);
    }

    /**
     * Return the rows currently buffered for the given parent key, falling back to the adapter when no replacement is pending.
     *
     * @param array<string, int|string> $parentKey
     *
     * @return list<array<string, mixed>>
     */
    public function rowsForParent(array $parentKey): array
    {
        $normalized = $this->normalizeParentKey($parentKey);
        $bufferKey = self::bufferKey($normalized);
        $buffered = $this->replacements[$bufferKey] ?? null;

        if (null !== $buffered) {
            return $buffered->rows;
        }

        return $this->adapter->rowsForParent($normalized);
    }

    public function flush(): void
    {
        $replacements = array_values($this->replacements);
        usort(
            $replacements,
            static fn (OwnedSetReplace $left, OwnedSetReplace $right): int => self::bufferKey($left->parentKey) <=> self::bufferKey($right->parentKey),
        );

        $deletes = array_values($this->parentMatchDeletes);
        usort(
            $deletes,
            static fn (OwnedSetParentMatchDelete $left, OwnedSetParentMatchDelete $right): int => self::bufferKey($left->partialParentKey) <=> self::bufferKey($right->partialParentKey),
        );

        $this->adapter->apply(new OwnedRowSetUpdatePlan($replacements, $deletes));
    }

    public function hasPendingChanges(): bool
    {
        return [] !== $this->replacements || [] !== $this->parentMatchDeletes;
    }

    public function clear(): void
    {
        $this->replacements = [];
        $this->parentMatchDeletes = [];
    }

    /**
     * @param array<string, int|string> $parentKey
     *
     * @return array<string, int|string>
     */
    private function normalizeParentKey(array $parentKey): array
    {
        if ([] === $parentKey) {
            throw new \InvalidArgumentException('Parent key must contain at least one column.');
        }

        foreach ($parentKey as $column => $value) {
            if (!is_string($column) || '' === $column) {
                throw new \InvalidArgumentException('Parent key columns must be non-empty strings.');
            }

            if (!is_int($value) && !is_string($value)) {
                throw new \InvalidArgumentException(sprintf(
                    'Parent key column "%s" must be an int or string.',
                    $column,
                ));
            }
        }

        ksort($parentKey, SORT_STRING);

        return $parentKey;
    }

    /**
     * @param array<string, int|string> $parentKey
     */
    private static function bufferKey(array $parentKey): string
    {
        return json_encode($parentKey, JSON_THROW_ON_ERROR);
    }

    /**
     * Shape check for partial parent keys (column subset validated by the adapter).
     *
     * @param array<string, int|string> $partial
     *
     * @return array<string, int|string>
     */
    private function normalizePartialParentKey(array $partial): array
    {
        if ([] === $partial) {
            throw new \InvalidArgumentException('Partial parent key must contain at least one column.');
        }

        foreach ($partial as $column => $value) {
            if (!is_string($column) || '' === $column) {
                throw new \InvalidArgumentException('Partial parent key columns must be non-empty strings.');
            }

            if (!is_int($value) && !is_string($value)) {
                throw new \InvalidArgumentException(sprintf(
                    'Partial parent key column "%s" must be an int or string.',
                    $column,
                ));
            }
        }

        ksort($partial, SORT_STRING);

        return $partial;
    }

    /**
     * @param array<string, int|string> $parentKey
     * @param array<string, int|string> $partial
     */
    private static function parentMatchesPartial(array $parentKey, array $partial): bool
    {
        foreach ($partial as $column => $value) {
            if (!array_key_exists($column, $parentKey) || $parentKey[$column] !== $value) {
                return false;
            }
        }

        return true;
    }
}
