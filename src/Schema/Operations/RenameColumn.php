<?php

declare(strict_types=1);

namespace Ultra\Schema\Operations;

final readonly class RenameColumn implements SchemaOperation
{
    public function __construct(
        public string $tableName,
        public string $fromName,
        public string $toName,
    ) {}
}
