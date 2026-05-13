<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

final readonly class ApplicationLifecycle
{
    public function __construct(
        private TransactionManager $transactionManager,
        private UnitOfWorkLifecycle $unitOfWorkLifecycle,
    ) {}

    /**
     * @template TResult
     *
     * @param callable(): TResult $operation
     *
     * @return TResult
     */
    public function run(callable $operation): mixed
    {
        if ($this->transactionManager->isActive()) {
            return $operation();
        }

        $this->transactionManager->begin();

        try {
            $result = $operation();

            $this->unitOfWorkLifecycle->flushBeforeCommit();
            $this->transactionManager->commit();
            $this->unitOfWorkLifecycle->flushAfterCommit();
            $this->unitOfWorkLifecycle->clear();

            return $result;
        } catch (\Throwable $exception) {
            if ($this->transactionManager->isActive()) {
                $this->transactionManager->rollback();
            }

            $this->unitOfWorkLifecycle->clear();

            throw $exception;
        }
    }
}
