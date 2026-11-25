<?php

declare(strict_types=1);

namespace Ultra\Schema\Operations;

use Ultra\Schema\ColumnType;
use Ultra\Schema\SqlType;

final readonly class AlterColumn implements SchemaOperation
{
    public ?SqlType $type;

    public function __construct(
        public string $tableName,
        public string $columnName,
        ColumnType|SqlType|string|null $type = null,
        public ?bool $nullable = null,
        public ?string $defaultExpression = null,
        public bool $dropDefault = false,
    ) {
        $this->type = null === $type ? null : SqlType::resolve($type);
    }
}
