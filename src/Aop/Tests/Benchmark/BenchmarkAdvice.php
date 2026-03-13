<?php

declare(strict_types=1);

namespace Ultra\Aop\Tests\Benchmark;

class BenchmarkAdvice
{
    private int $counter = 0;

    public function increment(): void
    {
        ++$this->counter;
    }

    public function incrementBy(int $value): void
    {
        $this->counter += $value;
    }

    public function around(callable $proceed, int $x): int
    {
        return $proceed($x) + 1;
    }

    public function getCounter(): int
    {
        return $this->counter;
    }

    public function reset(): void
    {
        $this->counter = 0;
    }
}
