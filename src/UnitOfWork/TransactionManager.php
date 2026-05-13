<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

interface TransactionManager
{
    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    public function isActive(): bool;
}
