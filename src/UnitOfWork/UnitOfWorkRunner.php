<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

interface UnitOfWorkRunner
{
    /**
     * Run application work and flush the unit of work if it succeeds.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed;
}
