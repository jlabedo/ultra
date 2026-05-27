<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/**
 * "Delete every owned set whose parent key matches this partial set of columns."
 *
 * The match is exact on the supplied columns. Columns not mentioned are
 * unconstrained, so `{capability_id: "X"}` against parent columns
 * `(subject_id, capability_id)` deletes every row whose `capability_id = X`,
 * regardless of `subject_id`.
 */
final readonly class OwnedSetParentMatchDelete
{
    /**
     * @param array<string, int|string> $partialParentKey
     */
    public function __construct(
        public array $partialParentKey,
    ) {}
}
