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

        $this->adapter->apply(new OwnedRowSetUpdatePlan($replacements));
    }

    public function hasPendingChanges(): bool
    {
        return [] !== $this->replacements;
    }

    public function clear(): void
    {
        $this->replacements = [];
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
}
