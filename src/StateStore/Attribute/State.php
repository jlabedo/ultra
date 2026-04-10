<?php

declare(strict_types=1);

namespace Ultra\StateStore\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class State
{
    public function __construct(
        public string $table,
        public string $idColumn = 'id',
    ) {}
}
