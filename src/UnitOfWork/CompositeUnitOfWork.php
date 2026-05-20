<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

/**
 * Composes several unit-of-work instances and delegates to them in the order they were provided.
 */
final readonly class CompositeUnitOfWork implements UnitOfWork
{
    /** @var list<UnitOfWork> */
    private array $unitOfWorks;

    /**
     * @param iterable<UnitOfWork> $unitOfWorks
     */
    public function __construct(iterable $unitOfWorks)
    {
        $this->unitOfWorks = is_array($unitOfWorks)
            ? array_values($unitOfWorks)
            : array_values(iterator_to_array($unitOfWorks, false));
    }

    public function flush(): void
    {
        foreach ($this->unitOfWorks as $unitOfWork) {
            $unitOfWork->flush();
        }
    }

    public function hasPendingChanges(): bool
    {
        foreach ($this->unitOfWorks as $unitOfWork) {
            if ($unitOfWork->hasPendingChanges()) {
                return true;
            }
        }

        return false;
    }

    public function clear(): void
    {
        foreach ($this->unitOfWorks as $unitOfWork) {
            $unitOfWork->clear();
        }
    }
}
