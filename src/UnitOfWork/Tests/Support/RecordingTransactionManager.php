<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests\Support;

use Ultra\UnitOfWork\TransactionManager;

final readonly class RecordingTransactionManager implements TransactionManager
{
    public function __construct(private CallLog $log) {}

    public function transactional(callable $callback): mixed
    {
        $this->log->entries[] = 'transaction:begin';
        try {
            $result = $callback();
            $this->log->entries[] = 'transaction:commit';

            return $result;
        } catch (\Throwable $failure) {
            $this->log->entries[] = 'transaction:rollback';

            throw $failure;
        }
    }
}
