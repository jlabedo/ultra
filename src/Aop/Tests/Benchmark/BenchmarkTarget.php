<?php

declare(strict_types=1);

namespace Ultra\Aop\Tests\Benchmark;

class BenchmarkTarget
{
    public function multiply(int $x): int
    {
        return $x * 2;
    }
}
