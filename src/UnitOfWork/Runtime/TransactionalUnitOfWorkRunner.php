<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\MatchResult;

final class TransactionalUnitOfWorkRunner implements UnitOfWorkRunner
{
    private bool $active = false;

    private bool $rollbackOnly = false;

    public function __construct(
        private UnitOfWork $unitOfWork,
        private TransactionManager $transactionManager,
    ) {}

    #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
        return MatchResult::apply();
    }, priority: -1000)]
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
