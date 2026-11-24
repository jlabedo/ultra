<?php

declare(strict_types=1);

namespace Ultra\Schema\Operations;

final readonly class CreateExtension implements SchemaOperation
{
    public function __construct(
        public string $name,
    ) {}
}
