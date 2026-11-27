<?php

declare(strict_types=1);

namespace Ultra\Schema\Operations;

use Ultra\Schema\PostgresPolicyDefinition;

final readonly class CreatePolicy implements SchemaOperation
{
    public function __construct(
        public string $tableName,
        public string $name,
        public PostgresPolicyDefinition $policy,
    ) {}
}
