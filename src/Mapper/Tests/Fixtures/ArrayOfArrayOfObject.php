<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

readonly class ArrayOfArrayOfObject
{
    /**
     * @param Address[][] $matrix
     */
    public function __construct(
        public array $matrix,
    ) {}
}
