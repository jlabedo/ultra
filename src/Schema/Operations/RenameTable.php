<?php

declare(strict_types=1);

namespace Ultra\Schema\Operations;

final readonly class RenameTable implements SchemaOperation
{
    public function __construct(
        public string $fromName,
        public string $toName,
    ) {
    }
}
