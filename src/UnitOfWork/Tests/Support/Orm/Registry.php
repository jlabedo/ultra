<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests\Support\Orm;

use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\ObjectManager;

final class Registry extends AbstractManagerRegistry
{
    /** @param array<string, ObjectManager> $currentManagers */
    public function __construct(
        public array $currentManagers,
    ) {
        parent::__construct(
            'test',
            [],
            array_combine(array_keys($currentManagers), array_keys($currentManagers)),
            '',
            'default',
        );
    }

    protected function getService(string $name): object
    {
        return $this->currentManagers[$name];
    }

    protected function resetService(string $name): void
    {
        throw new \LogicException('Ultra must not reset the manager.');
    }
}
