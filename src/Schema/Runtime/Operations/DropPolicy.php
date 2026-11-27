<?php

declare(strict_types=1);

namespace Ultra\Schema\Operations;

final readonly class DropPolicy implements SchemaOperation
{
    public function __construct(
        public string $tableName,
        public string $name,
    ) {}
}
