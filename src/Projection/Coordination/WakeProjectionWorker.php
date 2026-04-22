<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

final readonly class WakeProjectionWorker
{
    public function __construct(public string $projectionId) {}
}
