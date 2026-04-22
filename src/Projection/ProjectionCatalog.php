<?php

declare(strict_types=1);

namespace Ultra\Projection;

final class ProjectionCatalog
{
    /** @var array<string, ProjectionRuntime> */
    private array $runtimes = [];

    /** @var array<string, ProjectionDefinition> */
    private array $definitions = [];

    public function __construct(ProjectionRuntime ...$runtimes)
    {
        foreach ($runtimes as $runtime) {
            $this->add($runtime);
        }
    }

    public function add(ProjectionRuntime $runtime): void
    {
        $projectionId = $runtime->projectionId();
        $this->runtimes[$projectionId] = $runtime;
        $this->definitions[$projectionId] = $runtime->definition();
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
        if (!isset($this->definitions[$projectionId])) {
            throw new \InvalidArgumentException(sprintf('Unknown projection "%s".', $projectionId));
        }

        return $this->definitions[$projectionId];
    }

    /** @return list<ProjectionDefinition> */
    public function definitions(): array
    {
        return array_values($this->definitions);
    }
}
