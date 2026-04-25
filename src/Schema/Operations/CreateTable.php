<?php

declare(strict_types=1);

namespace Ultra\Schema\Operations;

use Ultra\Schema\TableDefinition;

final readonly class CreateTable implements SchemaOperation
{
    public function __construct(
        public TableDefinition $table,
    ) {
    }
}
