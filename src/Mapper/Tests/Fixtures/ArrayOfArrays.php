<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

readonly class ArrayOfArrays
{
    /**
     * @param int[][] $matrix
     */
    public function __construct(
        public array $matrix,
    ) {}
}
