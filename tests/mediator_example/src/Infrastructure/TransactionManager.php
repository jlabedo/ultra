<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Infrastructure;

final class TransactionManager
{
    /** @var list<string> */
    public array $log = [];

    public function begin(): void
    {
        $this->log[] = 'begin';
    }

    public function commit(): void
    {
        $this->log[] = 'commit';
    }

    public function rollback(): void
    {
        $this->log[] = 'rollback';
    }
}
