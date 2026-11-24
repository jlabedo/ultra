<?php

declare(strict_types=1);

namespace Ultra\Schema\Operations;

use Ultra\Schema\PostgresFunctionDefinition;

final readonly class CreateFunction implements SchemaOperation
{
    public function __construct(
        public PostgresFunctionDefinition $function,
        public bool $replace = false,
    ) {}
}
