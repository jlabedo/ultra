<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/** Membership of the whole table or of one declared owner's collection. */
final readonly class RowMembershipPlan
{
    /**
     * @param list<MappedKey>|null $expectedRowKeys committed keys expected at flush, excluding ignored keys
     * @param list<MappedKey> $ignoredRowKeys keys whose presence was decided by this unit of work at observation time
     * @param list<MappedKey>|null $retainedRowKeys final keys after reconciliation; null leaves membership unchanged
     */
    public function __construct(
        public ?MappedKey $owner,
        public ?array $expectedRowKeys,
        public array $ignoredRowKeys,
        public ?array $retainedRowKeys,
    ) {}

    /** @internal @param list<MappedRow> $rows */
    public function matchesExpectedRows(array $rows): bool
    {
        if (null === $this->expectedRowKeys) {
            return true;
        }

        $actual = array_map(static fn(MappedRow $row): string => $row->key->fingerprint(), $rows);
        $expected = array_map(static fn(MappedKey $key): string => $key->fingerprint(), $this->expectedRowKeys);
        $ignored = array_map(static fn(MappedKey $key): string => $key->fingerprint(), $this->ignoredRowKeys);
        $actual = array_values(array_diff($actual, $ignored));
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }
}
