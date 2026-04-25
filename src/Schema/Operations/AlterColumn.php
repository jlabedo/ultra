<?php

declare(strict_types=1);

namespace Ultra\Schema\Operations;

use Ultra\Schema\ColumnDefinition;

final readonly class AlterColumn implements SchemaOperation
{
    public function __construct(
        public string $tableName,
        public string $columnName,
        public ColumnDefinition $targetDefinition,
    ) {
    }
}
