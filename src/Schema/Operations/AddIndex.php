<?php

declare(strict_types=1);

namespace Ultra\Schema\Operations;

use Ultra\Schema\IndexDefinition;

final readonly class AddIndex implements SchemaOperation
{
    public function __construct(
        public string $tableName,
        public IndexDefinition $index,
    ) {
    }
}
