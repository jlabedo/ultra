<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests\Support;

use Ultra\UnitOfWork\UnitOfWork;

final class RecordingUnitOfWork implements UnitOfWork
{
    public bool $pending = false;
    public ?\Throwable $flushFailure = null;

    public function __construct(
        private CallLog $log,
        private string $name = 'unit_of_work',
    ) {}

    public function hasPendingChanges(): bool
    {
        return $this->pending;
    }

    public function flush(): void
    {
        $this->log->entries[] = $this->name . ':flush';
        if (null !== $this->flushFailure) {
            throw $this->flushFailure;
        }
        $this->pending = false;
    }

    public function clear(): void
    {
        $this->log->entries[] = $this->name . ':clear';
        $this->pending = false;
    }
}
