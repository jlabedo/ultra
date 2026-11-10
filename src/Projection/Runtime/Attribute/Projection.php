<?php

declare(strict_types=1);

namespace Ultra\Projection\Attribute;

use Ultra\Projection\ProjectionMode;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Projection
{
    /** @param list<string> $unitOfWork Projection-owned persistence service IDs. */
    public function __construct(
        public string $name,
        public ?string $partitionBy = null,
        public ProjectionMode $mode = ProjectionMode::Asynchronous,
        public array $unitOfWork = [],
    ) {
        if ('' === $this->name) {
            throw new \InvalidArgumentException('Projection name must be a non-empty string.');
        }

        if ('' === $this->partitionBy || (null === $this->partitionBy && ProjectionMode::SynchronousFromNow !== $this->mode)) {
            throw new \InvalidArgumentException('Projection partitionBy is required except for SynchronousFromNow.');
        }
    }
}
