<?php

declare(strict_types=1);

namespace Ultra\Projection;

final class ProjectionCatalog
{
    /** @var array<string, ProjectionPlan> */
    private array $plans = [];

    /** @var array<string, ProjectionDescriptor> */
    private array $descriptors = [];

    public function __construct(ProjectionPlan ...$plans)
    {
        foreach ($plans as $plan) {
            $this->add($plan);
        }
    }

    public function add(ProjectionPlan $plan): void
    {
        $projectionId = $plan->projectionId();
        $this->plans[$projectionId] = $plan;
        $this->descriptors[$projectionId] = ProjectionDescriptor::fromPlan($plan);
    }

    public function plan(string $projectionId): ProjectionPlan
    {
        if (!isset($this->plans[$projectionId])) {
            throw new \InvalidArgumentException(sprintf('Unknown projection "%s".', $projectionId));
        }

        return $this->plans[$projectionId];
    }

    public function descriptor(string $projectionId): ProjectionDescriptor
    {
        if (!isset($this->descriptors[$projectionId])) {
            throw new \InvalidArgumentException(sprintf('Unknown projection "%s".', $projectionId));
        }

        return $this->descriptors[$projectionId];
    }

    /** @return list<ProjectionDescriptor> */
    public function descriptors(): array
    {
        return array_values($this->descriptors);
    }
}
