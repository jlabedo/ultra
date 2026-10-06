<?php

declare(strict_types=1);

namespace Ultra\Projection;

final readonly class NoOpProjectionTransactionManager implements ProjectionTransactionManagerInterface
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
