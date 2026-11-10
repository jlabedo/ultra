<?php

declare(strict_types=1);

namespace Ultra\Projection;

final readonly class ProjectionState
{
    public function __construct(
        public string $projectionId,
        public int $generation,
        public ProjectionPhase $phase,
        public string $fingerprint,
    ) {}

    public static function fingerprint(ProjectionDefinition $definition): string
    {
        $types = $definition->handledEventTypes();
        sort($types);

        return hash('sha256', serialize([$definition->partitionDimension, $types]));
    }
}
