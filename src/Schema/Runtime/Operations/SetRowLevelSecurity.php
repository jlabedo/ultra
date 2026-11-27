<?php

declare(strict_types=1);

namespace Ultra\Schema\Operations;

use Ultra\Schema\RowLevelSecurity;

final readonly class SetRowLevelSecurity implements SchemaOperation
{
    public function __construct(
        public string $tableName,
        public RowLevelSecurity $mode,
    ) {}
}
