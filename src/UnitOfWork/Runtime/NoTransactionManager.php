<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

final readonly class NoTransactionManager implements TransactionManager
{
    public function transactional(callable $callback): mixed
    {
        return $callback();
    }
}
