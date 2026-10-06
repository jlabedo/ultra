<?php

declare(strict_types=1);

namespace Ultra\Schema\Operations;

final readonly class AlterColumn implements SchemaOperation
{
    public function __construct(
        public string $tableName,
        public string $columnName,
        public ?string $type = null,
        public ?bool $nullable = null,
        public ?string $defaultExpression = null,
        public bool $dropDefault = false,
    ) {}
}
