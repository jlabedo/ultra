<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

readonly class Foo
{
    public function __construct(
        public int $id,
        public string $name
    ) {}
}
