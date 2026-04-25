<?php

declare(strict_types=1);

namespace Ultra\Schema\Operations;

use Ultra\Schema\ColumnDefinition;

final readonly class AddColumn implements SchemaOperation
{
    public function __construct(
        public string $tableName,
        public ColumnDefinition $column,
    ) {
    }
}
