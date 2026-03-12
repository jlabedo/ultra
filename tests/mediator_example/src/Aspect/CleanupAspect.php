<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Aspect;

use Ultra\Mediator\Attribute\After;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\CleanupTracker;

final class CleanupAspect
{
    public function __construct(private readonly CleanupTracker $cleanup)
    {
    }

    #[After(priority: -5)]
    public function after(object $message, array $context): void
    {
        $this->cleanup->cleanup();
    }
}
