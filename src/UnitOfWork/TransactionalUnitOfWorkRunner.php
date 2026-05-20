<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

final class TransactionalUnitOfWorkRunner implements UnitOfWorkRunner
{
    private bool $active = false;

    private bool $rollbackOnly = false;

    public function __construct(
        private UnitOfWork $unitOfWork,
        private TransactionManager $transactionManager,
    ) {}

    public function run(callable $work): mixed
    {
        if ($this->active) {
            try {
                return $work();
            } catch (\Throwable $e) {
                $this->markRollbackOnly();

                throw $e;
            }
        }

        $this->active = true;
        $this->rollbackOnly = false;

        try {
            $result = $work();

            if (!$this->rollbackOnly && $this->unitOfWork->hasPendingChanges()) {
                $this->transactionManager->transactional(function (): void {
                    $this->unitOfWork->flush();
                });
            }

            return $result;
        } finally {
            $this->active = false;
            $this->rollbackOnly = false;
            $this->unitOfWork->clear();
        }
    }

    public function hasActiveTransaction(): bool
    {
        return $this->active;
    }

    public function markRollbackOnly(): void
    {
        if (!$this->active) {
            return;
        }

        $this->rollbackOnly = true;
    }

    public function isRollbackOnly(): bool
    {
        return $this->rollbackOnly;
    }
}
