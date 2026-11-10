<?php

declare(strict_types=1);

namespace Ultra\Projection;

interface ProjectionTransactionManagerInterface
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;
}
