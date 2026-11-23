<?php

declare(strict_types=1);

namespace Ultra\Projection\Attribute;

use Ultra\Projection\ProjectionMode;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Projection
{
    /**
     * @param list<string> $unitOfWork Projection-owned persistence service IDs.
     * @param list<string> $rowSets IDs of the mapped row sets owned by this projection.
     */
    public function __construct(
        public string $name,
        public string $partitionBy = '',
        public ProjectionMode $mode = ProjectionMode::Asynchronous,
        public array $unitOfWork = [],
        public array $rowSets = [],
    ) {
        if ('' === $this->name) {
            throw new \InvalidArgumentException('Projection name must be a non-empty string.');
        }

        if ('' === $this->partitionBy) {
            throw new \InvalidArgumentException('Projection partitionBy is required.');
        }
    }
}
