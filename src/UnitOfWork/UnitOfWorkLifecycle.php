<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

final readonly class UnitOfWorkLifecycle
{
    public function __construct(
        private UnitOfWork $beforeCommit,
        private UnitOfWork $afterCommit,
    ) {}

    public function flushBeforeCommit(): void
    {
        $this->beforeCommit->flush();
    }

    public function flushAfterCommit(): void
    {
        $this->afterCommit->flush();
    }

    public function clear(): void
    {
        $this->beforeCommit->clear();
        $this->afterCommit->clear();
    }
}
