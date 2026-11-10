<?php

declare(strict_types=1);

namespace Ultra\Projection;

/** State reads follow barrier acquisition in a READ COMMITTED transaction. */
interface ProjectionStateRepository
{
    /** Coordinate managed projection registration with every append, including older writers. */
    public function lockRegistrationShared(): void;

    public function lockRegistrationExclusive(): void;

    /** Hold a transaction-scoped shared barrier; ordinary writers remain concurrent. */
    public function lockShared(string $projectionId): void;

    /** Hold a transaction-scoped exclusive barrier for initialization, reset or activation. */
    public function lockExclusive(string $projectionId): void;

    public function find(string $projectionId): ?ProjectionState;

    /** @return list<ProjectionState> */
    public function all(): array;

    /** Caller holds the exclusive barrier. */
    public function save(ProjectionState $state): void;
}
