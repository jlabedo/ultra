<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

use Doctrine\Persistence\ManagerRegistry;

/** Participates in the caller's transaction without owning or renewing the Doctrine manager. */
final readonly class DoctrineUnitOfWork implements UnitOfWork
{
    public function __construct(
        private ManagerRegistry $registry,
        private ?string $managerName = null,
    ) {}

    public function hasPendingChanges(): bool
    {
        // Doctrine discovers changes to managed entities during flush.
        return true;
    }

    public function flush(): void
    {
        $this->registry->getManager($this->managerName)->flush();
    }

    public function clear(): void
    {
        $this->registry->getManager($this->managerName)->clear();
    }
}
