<?php

declare(strict_types=1);

namespace Ultra\Projection;

/** Runs projection work in its own transaction or joins the caller's active transaction. */
interface ProjectionTransactionManagerInterface
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;
}
