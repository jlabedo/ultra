<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Attribute;

use Ultra\MappedTable\MappedTableUnitOfWork;

/** Mark a public static factory for a compile-time MappedRowSetDefinition. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class DefineMappedRowSet
{
    public function __construct(
        public string $id,
        public string $unitOfWork = MappedTableUnitOfWork::class,
    ) {}
}
