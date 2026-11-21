<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final readonly class OwnerCollectionPlan
{
    /**
     * @param list<MappedKey>|null $expectedRowKeys
     * @param list<MappedKey> $ignoredRowKeys
     * @param list<MappedKey>|null $retainedRowKeys
     */
    public function __construct(
        public MappedKey $owner,
        public ?array $expectedRowKeys,
        public array $ignoredRowKeys,
        public ?array $retainedRowKeys,
    ) {}
}
