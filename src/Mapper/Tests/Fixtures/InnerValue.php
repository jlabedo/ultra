<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

use Ultra\Mapper\Attribute\AsValue;

#[AsValue]
readonly class InnerValue
{
    public function __construct(public int $inner) {}
}
