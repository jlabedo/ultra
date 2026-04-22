<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

final readonly class WakeProjectionCoordinator
{
    public function __construct(public string $projectionId) {}
}
