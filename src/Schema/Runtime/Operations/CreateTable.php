<?php

declare(strict_types=1);

namespace Ultra\Schema\Operations;

use Ultra\Schema\TableDefinition;

/** Create the table structure and indexes. The reconciler emits foreign keys and RLS operations separately. */
final readonly class CreateTable implements SchemaOperation
{
    public function __construct(
        public TableDefinition $table,
    ) {}
}
