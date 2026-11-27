<?php

declare(strict_types=1);

namespace Ultra\Projection;

/** State reads follow barrier acquisition in a READ COMMITTED transaction. */
interface ProjectionStateRepository
{
    /**
     * Hold the shared registration barrier, then a shared barrier for every match in the supplied order.
     *
     * @param non-empty-list<string> $projectionIds Matching IDs, including unregistered projections, sorted by ID.
     */
    public function lockForAppend(array $projectionIds): void;

    /** Coordinate managed projection registration with workers and appends handling synchronous projections. */
    public function lockRegistrationShared(): void;

    public function lockRegistrationExclusive(): void;

    /** Hold a transaction-scoped shared barrier; ordinary writers remain concurrent. */
    public function lockShared(string $projectionId): void;

    /** Hold a transaction-scoped exclusive barrier for initialization, reset or activation. */
    public function lockExclusive(string $projectionId): void;

    public function find(string $projectionId): ?ProjectionState;

    /**
     * @param list<string> $projectionIds
     * @return array<array-key, ProjectionState> Registered states keyed by projection ID.
     */
    public function findMany(array $projectionIds): array;

    /** @return list<ProjectionState> */
    public function all(): array;

    /** Caller holds the exclusive barrier. */
    public function save(ProjectionState $state): void;
}
