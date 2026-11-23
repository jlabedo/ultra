<?php

declare(strict_types=1);

namespace Ultra\Schema\Operations;

final readonly class AddForeignKey implements SchemaOperation
{
    /** @param array<string, string> $columns Local to referenced physical column names. */
    public function __construct(
        public string $tableName,
        public string $name,
        public string $referencedTableName,
        public array $columns,
    ) {
        if ([] === $columns) {
            throw new \InvalidArgumentException('A foreign key needs at least one column.');
        }
    }
}
