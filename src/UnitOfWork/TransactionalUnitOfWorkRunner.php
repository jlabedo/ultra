<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

final readonly class TransactionalUnitOfWorkRunner implements UnitOfWorkRunner
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private TransactionManager $transactionManager,
    ) {}

    public function run(callable $work): mixed
    {
        try {
            $result = $work();

            $this->transactionManager->transactional(function (): void {
                $this->unitOfWork->flush();
            });

            return $result;
        } finally {
            $this->unitOfWork->clear();
        }
    }
}
