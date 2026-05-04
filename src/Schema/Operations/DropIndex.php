<?php

declare(strict_types=1);

namespace Ultra\Schema\Operations;

final readonly class DropIndex implements SchemaOperation
{
    public function __construct(
        public string $tableName,
        public string $indexName,
    ) {}
}
