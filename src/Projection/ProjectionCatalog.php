<?php

declare(strict_types=1);

namespace Ultra\Projection;

final class ProjectionCatalog
{
    /** @var array<string, ProjectionRuntime> */
    private array $runtimes = [];

    public function __construct(ProjectionRuntime ...$runtimes)
    {
        foreach ($runtimes as $runtime) {
            $this->add($runtime);
        }
    }

    public function add(ProjectionRuntime $runtime): void
    {
        $this->runtimes[$runtime->projectionId()] = $runtime;
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
        return array_map(static fn (ProjectionRuntime $r): ProjectionDefinition => $r->definition(), array_values($this->runtimes));
    }

    /** @return list<ProjectionRuntime> */
    public function runtimes(): array
    {
        return array_values($this->runtimes);
    }
}
