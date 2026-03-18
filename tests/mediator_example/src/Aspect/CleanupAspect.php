<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Aspect;

use Ultra\MessageHandling\Attribute\After;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\CleanupTracker;

final class CleanupAspect
{
    public function __construct(private readonly CleanupTracker $cleanup)
    {
    }

    #[After(static function (\ReflectionMethod $method): ?MatchResult {
        return [] !== $method->getAttributes(Handles::class)
            ? MatchResult::apply()
            : null;
    }, priority: -5)]
    public function after(object $message, array $context): void
    {
        $this->cleanup->cleanup();
    }
}
