<?php

declare(strict_types=1);

namespace Ultra\Projection\InMemory;

use Ultra\Projection\ProjectionState;
use Ultra\Projection\ProjectionStateRepository;

/** Single-process adapter, without durable transactions or cross-process barriers. */
final class InMemoryProjectionStateRepository implements ProjectionStateRepository
{
    /** @var array<string, ProjectionState> */
    private array $states = [];

    public function lockRegistrationShared(): void {}

    public function lockRegistrationExclusive(): void {}

    public function lockShared(string $projectionId): void {}

    public function lockExclusive(string $projectionId): void {}

    public function find(string $projectionId): ?ProjectionState
    {
        return $this->states[$projectionId] ?? null;
    }

    public function all(): array
    {
        return array_values($this->states);
    }

    public function save(ProjectionState $state): void
    {
        $this->states[$state->projectionId] = $state;
    }
}
