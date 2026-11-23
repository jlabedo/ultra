<?php

declare(strict_types=1);

namespace Ultra\Projection;

final class ProjectionCatalog
{
    /** @var array<array-key, ProjectionRuntime> */
    private array $runtimes = [];

    /** @var list<ProjectionRuntime> Ordered by ID for transaction-scoped activation barriers. */
    private array $synchronousRuntimes = [];

    public function __construct(ProjectionRuntime ...$runtimes)
    {
        foreach ($runtimes as $runtime) {
            $this->add($runtime);
        }
    }

    public function add(ProjectionRuntime $runtime): void
    {
        foreach ($this->runtimes as $registered) {
            if ($registered->projectionId() === $runtime->projectionId()) {
                continue;
            }
            foreach ($registered->persistenceParticipants() as $existing) {
                foreach ($runtime->persistenceParticipants() as $participant) {
                    if ($existing === $participant) {
                        throw new \LogicException('Projection persistence participants must have a single owner.');
                    }
                }
            }
        }
        $id = $runtime->projectionId();
        $replaced = $this->runtimes[$id] ?? null;
        $this->runtimes[$id] = $runtime;

        if (
            ProjectionMode::Synchronous === $runtime->definition()->mode
            || ProjectionMode::Synchronous === $replaced?->definition()->mode
        ) {
            $synchronous = array_filter(
                $this->runtimes,
                static fn(ProjectionRuntime $candidate): bool => (
                    ProjectionMode::Synchronous === $candidate->definition()->mode
                ),
            );
            ksort($synchronous, SORT_STRING);
            $this->synchronousRuntimes = array_values($synchronous);
        }
    }

    /**
     * @internal Sorted once when synchronous registrations change.
     * @return list<ProjectionRuntime>
     */
    public function synchronousRuntimes(): array
    {
        return $this->synchronousRuntimes;
    }

    public function runtime(string $projectionId): ProjectionRuntime
    {
        if (!isset($this->runtimes[$projectionId])) {
            throw new \InvalidArgumentException(sprintf('Unknown projection "%s".', $projectionId));
        }

        return $this->runtimes[$projectionId];
    }

    public function definition(string $projectionId): ProjectionDefinition
    {
        return $this->runtime($projectionId)->definition();
    }

    /** @return list<ProjectionDefinition> */
    public function definitions(): array
    {
        return array_map(
            static fn(ProjectionRuntime $r): ProjectionDefinition => $r->definition(),
            array_values($this->runtimes),
        );
    }

    /** @return list<ProjectionRuntime> */
    public function runtimes(): array
    {
        return array_values($this->runtimes);
    }
}
